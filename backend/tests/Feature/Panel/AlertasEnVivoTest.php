<?php

use App\Enums\EstadoViaje;
use App\Filament\Resources\Alertas\AlertaResource;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Livewire\AvisoAlertas;
use App\Models\Alerta;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\Despachador;
use App\Servicios\MaquinaEstadosViaje;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => Queue::fake());

/** Las notificaciones de Filament que quedaron en la sesión (enviadas en el último request de Livewire). */
function notificacionesEnviadas(): array
{
    return array_values(session('filament.claimed_notifications') ?? session('filament.notifications') ?? []);
}

describe('alerta de viaje sin chofer', function () {
    it('se crea una sola vez cuando el viaje queda sin chofer', function () {
        $solicitante = Usuario::factory()->create(['nombre' => 'Ana Pérez']);
        $viaje = Viaje::factory()->create(['solicitante_id' => $solicitante->id]);

        app(Despachador::class)->despachar($viaje);
        app(MaquinaEstadosViaje::class)->intentar($viaje->fresh(), EstadoViaje::SinChofer);

        $alerta = Alerta::sole();
        expect($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer)
            ->and($alerta->tipo)->toBe(Alerta::VIAJE_SIN_CHOFER)
            ->and($alerta->viaje_id)->toBe($viaje->id)
            ->and($alerta->resuelta_en)->toBeNull()
            ->and($alerta->mensaje)->toBe("El viaje #{$viaje->id} (Ana Pérez) quedó sin chofer.");
    });

    it('se resuelve sola cuando el admin asigna el viaje', function () {
        $viaje = Viaje::factory()->create();
        app(Despachador::class)->despachar($viaje);
        $chofer = choferEnTurno(-34.601, -58.381);

        app(ServicioViaje::class)->reasignarPorAdmin($viaje->fresh(), $chofer);

        expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
            ->and(Alerta::sole()->resuelta_en)->not->toBeNull()
            ->and(Alerta::pendientes()->count())->toBe(0);
    });

    it('no toca las alertas de otros tipos ni de otros viajes', function () {
        $viaje = Viaje::factory()->create();
        $otro = Viaje::factory()->create();
        app(Despachador::class)->despachar($otro);
        $sinSenal = Alerta::create(['tipo' => Alerta::CHOFER_SIN_SENAL, 'viaje_id' => $viaje->id, 'mensaje' => 'x']);
        app(Despachador::class)->despachar($viaje);

        app(ServicioViaje::class)->reasignarPorAdmin($viaje->fresh(), choferEnTurno(-34.601, -58.381));

        expect($sinSenal->fresh()->resuelta_en)->toBeNull()
            ->and(Alerta::pendientes()->where('tipo', Alerta::VIAJE_SIN_CHOFER)->pluck('viaje_id')->all())->toBe([$otro->id]);
    });

    it('tiene etiqueta en la lista de alertas', function () {
        expect(AlertaResource::TIPOS[Alerta::VIAJE_SIN_CHOFER])->toBe('Viaje sin chofer');
    });
});

describe('aviso en vivo', function () {
    beforeEach(fn () => $this->actingAs(Usuario::factory()->admin()->create()));

    it('no avisa las alertas que ya existían al abrir la página', function () {
        Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'Vieja']);

        Livewire::test(AvisoAlertas::class)
            ->call('revisar')
            ->assertNotDispatched('alertas-nuevas');

        expect(notificacionesEnviadas())->toBe([]);
    });

    it('avisa las alertas pendientes nuevas, con sonido y un botón Ver', function () {
        $componente = Livewire::test(AvisoAlertas::class);
        $viaje = Viaje::factory()->create();
        Alerta::create(['tipo' => Alerta::VIAJE_SIN_CHOFER, 'viaje_id' => $viaje->id, 'mensaje' => 'El viaje quedó sin chofer.']);
        Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'Sin viaje']);
        Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'Ya resuelta', 'resuelta_en' => now()]);

        $componente->call('revisar')->assertDispatched('alertas-nuevas');

        $avisos = notificacionesEnviadas();
        expect($avisos)->toHaveCount(2)
            ->and($avisos[0]['title'])->toBe('Viaje sin chofer')
            ->and($avisos[0]['body'])->toBe('El viaje quedó sin chofer.')
            ->and($avisos[0]['status'])->toBe('warning')
            ->and($avisos[0]['duration'])->toBe('persistent')
            ->and($avisos[0]['actions'][0]['label'])->toBe('Ver')
            ->and($avisos[0]['actions'][0]['url'])->toBe(ViajeResource::getUrl('view', ['record' => $viaje->id]))
            ->and($avisos[1]['title'])->toBe('Reserva sin turno')
            ->and($avisos[1]['actions'][0]['url'])->toBe(AlertaResource::getUrl('index'));
    });

    it('no repite lo que ya avisó', function () {
        $componente = Livewire::test(AvisoAlertas::class);
        Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'Una']);
        $componente->call('revisar')->assertDispatched('alertas-nuevas');
        session()->forget(['filament.notifications', 'filament.claimed_notifications']);

        $componente->call('revisar')->assertNotDispatched('alertas-nuevas');

        expect(notificacionesEnviadas())->toBe([]);
    });

    it('muestra hasta 3 avisos y resume el resto', function () {
        $componente = Livewire::test(AvisoAlertas::class);
        foreach (range(1, 5) as $n) {
            Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => "Alerta $n"]);
        }

        $componente->call('revisar')->assertDispatched('alertas-nuevas');

        $avisos = notificacionesEnviadas();
        expect($avisos)->toHaveCount(4)
            ->and(array_column(array_slice($avisos, 0, 3), 'body'))->toBe(['Alerta 1', 'Alerta 2', 'Alerta 3'])
            ->and($avisos[3]['title'])->toBe('Y 2 alertas más')
            ->and($avisos[3]['actions'][0]['url'])->toBe(AlertaResource::getUrl('index'));

        $componente->call('revisar')->assertNotDispatched('alertas-nuevas');
    });

    it('consulta cada 10 segundos y respeta el silencio guardado en el navegador', function () {
        Livewire::test(AvisoAlertas::class)
            ->assertSeeHtml('wire:poll.10s')
            ->assertSeeHtml('vehiculos.alertas.silencio')
            ->assertSeeHtml('x-on:alertas-nuevas.window')
            ->assertSeeHtml('alerta.wav');
    });
});

it('monta el aviso y el botón de silencio en las páginas del panel', function () {
    $this->actingAs(Usuario::factory()->admin()->create());

    foreach (['/admin', AlertaResource::getUrl('index')] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('wire:poll.10s', escape: false)
            ->assertSee('Silenciar alertas')
            ->assertSee('Activar sonido de alertas');
    }
});

it('no monta el aviso en el login', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertDontSee('vehiculos.alertas.silencio');
});

it('el sonido de alerta existe y es un WAV corto', function () {
    $ruta = public_path('sonidos/alerta.wav');

    expect(is_file($ruta))->toBeTrue()
        ->and(filesize($ruta))->toBeLessThan(60 * 1024)
        ->and(substr(file_get_contents($ruta), 0, 4))->toBe('RIFF');
});
