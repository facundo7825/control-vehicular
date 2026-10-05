<?php

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Events\ViajeActualizado;
use App\Excepciones\ReglaNegocio;
use App\Excepciones\TransicionInvalida;
use App\Jobs\AlertarReservaSinTurno;
use App\Jobs\RecordarReserva;
use App\Jobs\VencerOferta;
use App\Models\Alerta;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\Despachador;
use App\Servicios\MaquinaEstadosViaje;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\NotificadorFalso;

beforeEach(function () {
    // Cola sync para que corra el listener de push; los jobs con retraso se falsean.
    Bus::fake([VencerOferta::class, RecordarReserva::class, AlertarReservaSinTurno::class]);
    $this->push = new NotificadorFalso;
    $this->app->instance(Notificador::class, $this->push);
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
});

it('asigna un inmediato que se está buscando a un chofer libre, con el vehículo de su turno', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Buscando]);

    app(ServicioViaje::class)->asignarPorAdmin($viaje, $chofer);

    expect($viaje->fresh())
        ->estado->toBe(EstadoViaje::Aceptado)
        ->chofer_id->toBe($chofer->id)
        ->vehiculo_id->toBe($chofer->turnoAbierto->vehiculo_id)
        ->aceptado_en->not->toBeNull();
    // Mismos avisos que una reasignación hecha por el admin.
    expect($this->push->titulosPara($chofer))->toBe(['Viaje asignado'])
        ->and($this->push->titulosPara($viaje->solicitante))->toBe(['Tu auto está confirmado']);
});

it('asignar un viaje ofrecido vence la oferta y avisa al chofer que la tenía', function () {
    $ofrecido = choferEnTurno(-34.60, -58.38);
    $viaje = Viaje::factory()->create(['origen_lat' => -34.60, 'origen_lng' => -58.38]);
    app(Despachador::class)->despachar($viaje);
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Ofrecido);
    $nuevo = choferEnTurno(-34.70, -58.50);
    Event::fake([ViajeActualizado::class]);

    app(ServicioViaje::class)->asignarPorAdmin($viaje->fresh(), $nuevo);

    expect($viaje->fresh()->chofer_id)->toBe($nuevo->id)
        ->and(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Expirada);
    Event::assertDispatched(ViajeActualizado::class,
        fn ($e) => $e->porAdmin && in_array($ofrecido->id, $e->choferesConOferta, true));
});

it('asignar una reserva sin chofer resuelve la alerta y programa los recordatorios', function () {
    $reserva = reservaBuscando();
    app(MaquinaEstadosViaje::class)->transicionar($reserva, EstadoViaje::SinChofer);
    $alerta = Alerta::where('tipo', Alerta::VIAJE_SIN_CHOFER)->sole();
    $chofer = Usuario::factory()->chofer()->create();

    app(ServicioViaje::class)->asignarPorAdmin($reserva->fresh(), $chofer);

    expect($reserva->fresh())
        ->estado->toBe(EstadoViaje::Aceptado)
        ->chofer_id->toBe($chofer->id)
        ->vehiculo_id->toBeNull();
    expect($alerta->fresh()->resuelta_en)->not->toBeNull();
    expect($this->push->titulosPara($chofer))->toBe(['Reserva asignada']);
    Bus::assertDispatched(RecordarReserva::class, fn ($j) => $j->choferId === $chofer->id);
});

it('respeta la elegibilidad: libre ahora para un inmediato y franja libre para una reserva', function () {
    $ocupado = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $ocupado->id, 'estado' => EstadoViaje::EnCurso]);
    $inmediato = Viaje::factory()->create(['estado' => EstadoViaje::SinChofer]);

    expect(fn () => app(ServicioViaje::class)->asignarPorAdmin($inmediato, $ocupado))
        ->toThrow(ReglaNegocio::class, 'El chofer elegido no está libre.');
    expect($inmediato->fresh()->estado)->toBe(EstadoViaje::SinChofer);

    $conReserva = Usuario::factory()->chofer()->create();
    reservaAceptada($conReserva, Carbon::parse('2026-10-02 15:30'));
    $reserva = reservaBuscando(['estado' => EstadoViaje::SinChofer]);

    expect(fn () => app(ServicioViaje::class)->asignarPorAdmin($reserva, $conReserva))
        ->toThrow(ReglaNegocio::class, 'El chofer tiene otra reserva en ese horario.');
    expect($reserva->fresh()->chofer_id)->toBeNull();
});

it('no asigna un viaje que ya tiene chofer o terminó: para eso está reasignar', function () {
    $aceptado = Viaje::factory()->create(['chofer_id' => choferEnTurno()->id, 'estado' => EstadoViaje::Aceptado]);
    $cancelado = Viaje::factory()->create(['estado' => EstadoViaje::Cancelado]);

    expect(fn () => app(ServicioViaje::class)->asignarPorAdmin($aceptado, choferEnTurno()))
        ->toThrow(ReglaNegocio::class, 'El viaje ya tiene chofer o terminó; no se puede asignar.')
        ->and(fn () => app(ServicioViaje::class)->asignarPorAdmin($cancelado, choferEnTurno()))
        ->toThrow(ReglaNegocio::class, 'El viaje ya tiene chofer o terminó; no se puede asignar.');

    expect(ServicioViaje::asignable(Viaje::factory()->make(['estado' => EstadoViaje::Buscando])))->toBeTrue()
        ->and(ServicioViaje::asignable(Viaje::factory()->make(['estado' => EstadoViaje::Ofrecido])))->toBeTrue()
        ->and(ServicioViaje::asignable(Viaje::factory()->make(['estado' => EstadoViaje::SinChofer])))->toBeTrue()
        ->and(ServicioViaje::asignable($aceptado))->toBeFalse();
});

it('la máquina de estados solo asigna viajes sin chofer', function () {
    $aceptado = Viaje::factory()->create(['chofer_id' => choferEnTurno()->id, 'estado' => EstadoViaje::Aceptado]);

    expect(fn () => app(MaquinaEstadosViaje::class)->asignar($aceptado, choferEnTurno()->id, null))
        ->toThrow(TransicionInvalida::class);
});
