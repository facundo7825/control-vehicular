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
use App\Servicios\Asignador;
use App\Servicios\Despachador;
use App\Servicios\MaquinaEstadosViaje;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\NotificadorFalso;

beforeEach(function () {
    // Cola sync para que corra el listener de push; los jobs con retraso se falsean y se inspeccionan.
    Bus::fake([VencerOferta::class, RecordarReserva::class, AlertarReservaSinTurno::class]);
    $this->push = new NotificadorFalso();
    $this->app->instance(Notificador::class, $this->push);
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
});

function inmediatoDe(Usuario $chofer, EstadoViaje $estado, array $attrs = []): Viaje
{
    return Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'vehiculo_id' => $chofer->turnoAbierto?->vehiculo_id,
        'estado' => $estado,
        'aceptado_en' => now()->subMinutes(10),
        ...$attrs,
    ]);
}

it('reasigna un inmediato en camino a un chofer libre con el vehículo de su turno', function () {
    $anterior = choferEnTurno();
    $nuevo = choferEnTurno();
    $viaje = inmediatoDe($anterior, EstadoViaje::Llego, ['llego_en' => now()]);

    app(ServicioViaje::class)->reasignarPorAdmin($viaje, $nuevo);

    expect($viaje->fresh())
        ->estado->toBe(EstadoViaje::Aceptado)
        ->chofer_id->toBe($nuevo->id)
        ->vehiculo_id->toBe($nuevo->turnoAbierto->vehiculo_id)
        ->llego_en->toBeNull();
    expect($this->push->titulosPara($anterior))->toBe(['Viaje reasignado'])
        ->and($this->push->titulosPara($nuevo))->toBe(['Viaje asignado'])
        ->and($this->push->titulosPara($viaje->solicitante))->toBe(['Tu auto está confirmado']);
});

it('asigna directo un viaje no obligatorio sin chofer, sin pasar por una oferta', function () {
    $nuevo = choferEnTurno();
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::SinChofer]);

    app(ServicioViaje::class)->reasignarPorAdmin($viaje, $nuevo);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and(OfertaViaje::count())->toBe(0);
});

it('reasignar un viaje ofrecido vence la oferta y avisa al chofer que la tenía', function () {
    $ofrecido = choferEnTurno(-34.60, -58.38);
    $viaje = Viaje::factory()->create(['origen_lat' => -34.60, 'origen_lng' => -58.38]);
    app(Despachador::class)->despachar($viaje);
    $nuevo = choferEnTurno(-34.70, -58.50);
    Event::fake([ViajeActualizado::class]);

    app(ServicioViaje::class)->reasignarPorAdmin($viaje->fresh(), $nuevo);

    expect($viaje->fresh()->chofer_id)->toBe($nuevo->id)
        ->and(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Expirada);
    Event::assertDispatched(ViajeActualizado::class,
        fn ($e) => $e->porAdmin && in_array($ofrecido->id, $e->choferesConOferta, true));
});

it('no reasigna un inmediato a un chofer que no está libre', function () {
    $ocupado = choferEnTurno();
    inmediatoDe($ocupado, EstadoViaje::EnCurso);
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Buscando]);

    expect(fn () => app(ServicioViaje::class)->reasignarPorAdmin($viaje, $ocupado))
        ->toThrow(ReglaNegocio::class, 'El chofer elegido no está libre.');
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Buscando);
});

it('no reasigna un viaje ya iniciado, al mismo chofer ni a quien no es chofer activo', function () {
    $chofer = choferEnTurno();
    $enCurso = inmediatoDe($chofer, EstadoViaje::EnCurso);
    $aceptado = inmediatoDe(choferEnTurno(), EstadoViaje::Aceptado);
    $inactivo = choferEnTurno();
    $inactivo->update(['activo' => false]);
    $servicio = app(ServicioViaje::class);

    expect(fn () => $servicio->reasignarPorAdmin($enCurso, choferEnTurno()))
        ->toThrow(ReglaNegocio::class, 'El viaje ya comenzó o terminó; no se puede reasignar.')
        ->and(fn () => $servicio->reasignarPorAdmin($aceptado, $aceptado->chofer))
        ->toThrow(ReglaNegocio::class, 'El viaje ya está asignado a ese chofer.')
        ->and(fn () => $servicio->reasignarPorAdmin($aceptado, $inactivo))
        ->toThrow(ReglaNegocio::class, 'El chofer elegido no existe o no está activo.')
        ->and(fn () => $servicio->reasignarPorAdmin($aceptado, Usuario::factory()->create()))
        ->toThrow(ReglaNegocio::class, 'El chofer elegido no existe o no está activo.');
});

it('reasigna una reserva aceptada a un chofer con la franja libre aunque esté fuera de turno', function () {
    $anterior = Usuario::factory()->chofer()->create();
    $nuevo = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($anterior, Carbon::parse('2026-10-02 15:00'), attrs: ['obligatorio' => true]);

    app(ServicioViaje::class)->reasignarPorAdmin($viaje, $nuevo);

    expect($viaje->fresh())
        ->estado->toBe(EstadoViaje::Aceptado)
        ->chofer_id->toBe($nuevo->id)
        ->vehiculo_id->toBeNull();
    expect($this->push->titulosPara($anterior))->toBe(['Reserva reasignada'])
        ->and($this->push->titulosPara($nuevo))->toBe(['Reserva asignada'])
        ->and($this->push->titulosPara($viaje->solicitante))->toBe(['Reserva confirmada para 02/10 12:00']);
});

it('al reasignar una reserva los recordatorios del chofer anterior no hacen nada y se programan los del nuevo', function () {
    $anterior = Usuario::factory()->chofer()->create();
    $nuevo = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($anterior, Carbon::parse('2026-10-02 15:00'));
    $marca = $viaje->programado_para->getTimestamp();

    app(ServicioViaje::class)->reasignarPorAdmin($viaje, $nuevo);

    Bus::assertDispatched(RecordarReserva::class, fn ($j) => $j->choferId === $nuevo->id);
    Bus::assertDispatched(AlertarReservaSinTurno::class, fn ($j) => $j->choferId === $nuevo->id);

    $this->push->enviados = [];
    (new RecordarReserva($viaje->id, $anterior->id, $marca))->handle($this->push);
    (new AlertarReservaSinTurno($viaje->id, $anterior->id, $marca))->handle($this->push);
    expect($this->push->enviados)->toBe([])
        ->and(Alerta::count())->toBe(0);
});

it('no reasigna una reserva a un chofer con otra reserva superpuesta', function () {
    $anterior = Usuario::factory()->chofer()->create();
    $ocupado = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($anterior, Carbon::parse('2026-10-02 15:00'));
    reservaAceptada($ocupado, Carbon::parse('2026-10-02 15:30'));

    expect(fn () => app(ServicioViaje::class)->reasignarPorAdmin($viaje, $ocupado))
        ->toThrow(ReglaNegocio::class, 'El chofer tiene otra reserva en ese horario.');
    expect($viaje->fresh()->chofer_id)->toBe($anterior->id);
});

it('no reasigna una reserva en la que el chofer ya salió', function () {
    $viaje = reservaAceptada(choferEnTurno(), now()->addMinutes(30), attrs: ['estado' => EstadoViaje::EnCamino]);

    expect(fn () => app(ServicioViaje::class)->reasignarPorAdmin($viaje, Usuario::factory()->chofer()->create()))
        ->toThrow(ReglaNegocio::class, 'La reserva ya comenzó o terminó; no se puede reasignar.');
});

it('la máquina de estados rechaza reasignar un viaje terminado', function () {
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado]);

    expect(fn () => app(MaquinaEstadosViaje::class)->reasignar($viaje, choferEnTurno()->id, null))
        ->toThrow(TransicionInvalida::class);
});

// Carreras simuladas con copias leídas antes de que otro request cambiara los datos.

it('con datos viejos no reasigna un viaje que el solicitante ya canceló', function () {
    $viaje = inmediatoDe(choferEnTurno(), EstadoViaje::Aceptado);
    $copiaDelPanel = Viaje::find($viaje->id);
    app(ServicioViaje::class)->cancelarPorSolicitante(Viaje::find($viaje->id), $viaje->solicitante, null);

    expect(fn () => app(ServicioViaje::class)->reasignarPorAdmin($copiaDelPanel, choferEnTurno()))
        ->toThrow(ReglaNegocio::class, 'El viaje ya comenzó o terminó; no se puede reasignar.');
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado);
});

it('con datos viejos del chofer no le asigna un segundo viaje activo', function () {
    $chofer = choferEnTurno();
    $copiaDelChofer = Usuario::find($chofer->id);
    $obligatorio = Viaje::factory()->create(['obligatorio' => true]);
    $aReasignar = Viaje::factory()->create(['estado' => EstadoViaje::SinChofer]);

    app(Asignador::class)->asignar($obligatorio, $chofer);

    expect(fn () => app(ServicioViaje::class)->reasignarPorAdmin($aReasignar, $copiaDelChofer))
        ->toThrow(ReglaNegocio::class, 'El chofer elegido no está libre.');
    expect(Viaje::activosDeChofer($chofer->id)->pluck('id')->all())->toBe([$obligatorio->id]);
});
