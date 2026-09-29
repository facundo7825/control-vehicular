<?php

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Excepciones\TransicionInvalida;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\Despachador;
use App\Servicios\MaquinaEstadosViaje;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\Fakes\NotificadorFalso;

beforeEach(function () {
    // Cola sync para que corra el listener de push; los jobs con retraso se falsean.
    Bus::fake([App\Jobs\VencerOferta::class, App\Jobs\RecordarReserva::class, App\Jobs\AlertarReservaSinTurno::class]);
    $this->push = new NotificadorFalso();
    $this->app->instance(Notificador::class, $this->push);
    $this->admin = Usuario::factory()->admin()->create();
});

function viajeDeChofer(Usuario $chofer, EstadoViaje $estado, array $attrs = []): Viaje
{
    return Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'vehiculo_id' => $chofer->turnoAbierto?->vehiculo_id,
        'estado' => $estado,
        'aceptado_en' => now(),
        ...$attrs,
    ]);
}

it('solo el admin tiene la transición de en_curso a cancelado', function () {
    $maquina = app(MaquinaEstadosViaje::class);

    expect($maquina->puede(EstadoViaje::EnCurso, EstadoViaje::Cancelado))->toBeFalse()
        ->and($maquina->puede(EstadoViaje::EnCurso, EstadoViaje::Cancelado, comoAdmin: true))->toBeTrue()
        ->and($maquina->puede(EstadoViaje::Finalizado, EstadoViaje::Cancelado, comoAdmin: true))->toBeFalse();
});

it('el admin cancela un viaje obligatorio en curso y avisa a ambos', function () {
    $chofer = choferEnTurno();
    $viaje = viajeDeChofer($chofer, EstadoViaje::EnCurso, ['obligatorio' => true]);

    app(ServicioViaje::class)->cancelarPorAdmin($viaje, $this->admin, '  Vehículo averiado  ');

    expect($viaje->fresh())
        ->estado->toBe(EstadoViaje::Cancelado)
        ->cancelado_por->toBe('admin')
        ->motivo_cancelacion->toBe('Vehículo averiado')
        ->cancelado_en->not->toBeNull();
    expect($this->push->titulosPara($viaje->solicitante))->toBe(['Viaje cancelado'])
        ->and($this->push->titulosPara($chofer))->toBe(['Viaje cancelado'])
        ->and(collect($this->push->enviados)->firstWhere('destino', $viaje->solicitante_id)['cuerpo'])
        ->toBe('Un administrador canceló el viaje. Motivo: Vehículo averiado');
});

it('el admin cancela una reserva aceptada con el texto de reservas', function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($chofer, Carbon::parse('2026-10-02 15:00'), attrs: ['obligatorio' => true]);

    app(ServicioViaje::class)->cancelarPorAdmin($viaje, $this->admin, 'Se suspendió la audiencia');

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado)
        ->and($this->push->titulosPara($chofer))->toBe(['Reserva cancelada'])
        ->and(collect($this->push->enviados)->firstWhere('destino', $chofer->id)['cuerpo'])
        ->toBe('Un administrador canceló la reserva del 02/10 12:00.');
});

it('cancelar un viaje ofrecido vence la oferta pendiente', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['origen_lat' => -34.60, 'origen_lng' => -58.38]);
    app(Despachador::class)->despachar($viaje);

    app(ServicioViaje::class)->cancelarPorAdmin($viaje->fresh(), $this->admin, 'Pedido duplicado');

    expect(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Expirada)
        ->and($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado);
    expect(fn () => app(Despachador::class)->responder(OfertaViaje::sole(), true))
        ->toThrow(ReglaNegocio::class, 'La oferta ya no está vigente.');
});

it('no cancela viajes terminados ni sin motivo', function (EstadoViaje $estado, string $motivo, string $mensaje) {
    $viaje = Viaje::factory()->create(['estado' => $estado]);

    expect(fn () => app(ServicioViaje::class)->cancelarPorAdmin($viaje, $this->admin, $motivo))
        ->toThrow(ReglaNegocio::class, $mensaje);
    expect($viaje->fresh()->estado)->toBe($estado);
})->with([
    [EstadoViaje::Finalizado, 'x', 'El viaje ya terminó; no se puede cancelar.'],
    [EstadoViaje::SinChofer, 'x', 'El viaje ya terminó; no se puede cancelar.'],
    [EstadoViaje::Cancelado, 'x', 'El viaje ya estaba cancelado.'],
    [EstadoViaje::Buscando, '   ', 'Indicá el motivo de la cancelación.'],
]);

it('solo un admin puede usar la cancelación del panel', function () {
    $viaje = Viaje::factory()->create();

    expect(fn () => app(ServicioViaje::class)->cancelarPorAdmin($viaje, $viaje->solicitante, 'x'))
        ->toThrow(AccionNoPermitida::class);
});

it('el solicitante sigue sin poder cancelar un viaje en curso', function () {
    $viaje = viajeDeChofer(choferEnTurno(), EstadoViaje::EnCurso);

    expect(fn () => app(ServicioViaje::class)->cancelarPorSolicitante($viaje, $viaje->solicitante, null))
        ->toThrow(TransicionInvalida::class);
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::EnCurso);
});

it('con datos viejos no cancela un viaje que el chofer ya finalizó', function () {
    $chofer = choferEnTurno();
    $viaje = viajeDeChofer($chofer, EstadoViaje::EnCurso);
    $copiaDelPanel = Viaje::find($viaje->id);

    app(ServicioViaje::class)->avanzar(Viaje::find($viaje->id), $chofer, EstadoViaje::Finalizado);

    expect(fn () => app(ServicioViaje::class)->cancelarPorAdmin($copiaDelPanel, $this->admin, 'x'))
        ->toThrow(ReglaNegocio::class, 'El viaje ya terminó; no se puede cancelar.');
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Finalizado);
});
