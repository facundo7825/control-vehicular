<?php

use App\Enums\EstadoViaje;
use App\Filament\Resources\Alertas\AlertaResource;
use App\Filament\Resources\Alertas\Pages\ListAlertas;
use App\Filament\Widgets\ResumenOperativo;
use App\Models\Alerta;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\ResumenPanel;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $this->actingAs(Usuario::factory()->admin()->create());
});

it('lista primero las pendientes y las marca como resueltas', function () {
    $pendienteVieja = Alerta::create(['tipo' => Alerta::CHOFER_SIN_SENAL, 'mensaje' => 'A']);
    $this->travel(10)->minutes();
    $resuelta = Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'Resuelta', 'resuelta_en' => now()]);
    $pendienteNueva = Alerta::create(['tipo' => Alerta::CHOFER_SIN_SENAL, 'mensaje' => 'B']);

    Livewire::test(ListAlertas::class)
        ->assertCanSeeTableRecords([$pendienteNueva, $pendienteVieja, $resuelta], inOrder: true)
        ->assertActionHidden(TestAction::make('resolver')->table($resuelta))
        ->callAction(TestAction::make('resolver')->table($pendienteVieja));

    expect($pendienteVieja->fresh()->resuelta_en)->not->toBeNull()
        ->and(AlertaResource::getNavigationBadge())->toBe('1');
});

it('filtra las pendientes', function () {
    $resuelta = Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'x', 'resuelta_en' => now()]);
    $pendiente = Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'y']);

    Livewire::test(ListAlertas::class)
        ->filterTable('resuelta_en', false)
        ->assertCanSeeTableRecords([$pendiente])
        ->assertCanNotSeeTableRecords([$resuelta]);
});

it('cuenta alertas pendientes, viajes sin chofer de las últimas 24 h y choferes sin señal en viaje', function () {
    Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'x']);
    Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'y', 'resuelta_en' => now()]);
    Viaje::factory()->create(['estado' => EstadoViaje::SinChofer]);
    Viaje::factory()->create(['estado' => EstadoViaje::SinChofer, 'updated_at' => now()->subDays(2)]);
    $sinSenal = choferEnTurno(minutos: 5);
    Viaje::factory()->create(['chofer_id' => $sinSenal->id, 'estado' => EstadoViaje::EnCurso]);
    choferEnTurno(minutos: 5); // sin señal pero sin viaje: no cuenta

    $resumen = app(ResumenPanel::class);

    expect($resumen->alertasPendientes())->toBe(1)
        ->and($resumen->viajesSinChoferRecientes())->toBe(1)
        ->and($resumen->choferesSinSenalEnViaje()->pluck('id')->all())->toBe([$sinSenal->id]);

    Livewire::test(ResumenOperativo::class)
        ->assertSee('Alertas sin resolver')
        ->assertSee('Choferes sin señal en viaje')
        ->assertSee($sinSenal->nombre);
});

it('muestra el resumen en el tablero', function () {
    // Los widgets cargan en diferido: la página trae el componente y este dibuja los números.
    $this->get('/admin')->assertOk()->assertSeeLivewire(ResumenOperativo::class);
});
