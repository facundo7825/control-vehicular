<?php

use App\Enums\EstadoViaje;
use App\Jobs\AlertarReservaSinTurno;
use App\Jobs\RecordarReserva;
use App\Jobs\VencerOferta;
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
use Tests\Fakes\NotificadorFalso;

beforeEach(function () {
    $this->push = new NotificadorFalso();
    $this->app->instance(Notificador::class, $this->push);
});

it('avisa al chofer de una nueva oferta', function () {
    $chofer = choferEnTurno();

    // Con la cola sync el vencimiento correría en el acto; se falsea solo ese job.
    Illuminate\Support\Facades\Bus::fake([App\Jobs\VencerOferta::class]);
    app(Despachador::class)->despachar(Viaje::factory()->create(['destino_direccion' => 'Tribunales']));

    expect($this->push->titulosPara($chofer))->toBe(['Nuevo pedido de viaje'])
        ->and($this->push->enviados[0]['datos'])->toMatchArray(['tipo' => 'oferta']);
});

it('avisa al chofer que tiene un viaje obligatorio asignado y al solicitante que está confirmado', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['obligatorio' => true]);

    app(Despachador::class)->despachar($viaje);

    expect($this->push->titulosPara($chofer))->toBe(['Viaje asignado'])
        ->and($this->push->titulosPara($viaje->solicitante))->toBe(['Tu auto está confirmado']);
});

it('avisa al solicitante cuando el chofer llegó', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::EnCamino]);

    app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::Llego);

    expect($this->push->titulosPara($viaje->solicitante))->toBe(['Tu auto llegó']);
});

it('avisa al solicitante si no hay choferes', function () {
    $viaje = Viaje::factory()->create();

    app(Despachador::class)->despachar($viaje);

    expect($this->push->titulosPara($viaje->solicitante))->toBe(['No hay choferes disponibles']);
});

it('avisa al chofer si el solicitante canceló', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);

    app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::Cancelado, ['cancelado_por' => 'solicitante']);

    expect($this->push->titulosPara($chofer))->toBe(['Viaje cancelado']);
});

it('avisa al solicitante si su chofer canceló', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);

    app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::Buscando, ['chofer_id' => null]);

    expect($this->push->titulosPara($viaje->solicitante))->toBe(['Tu chofer canceló']);
});

// Con la cola sync los jobs con retraso correrían en el acto: se falsean solo esos.
function sinJobsDiferidos(): void
{
    Bus::fake([VencerOferta::class, RecordarReserva::class, AlertarReservaSinTurno::class]);
}

it('avisa al chofer de una solicitud de reserva con su fecha y hora', function () {
    sinJobsDiferidos();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $chofer = Usuario::factory()->chofer()->create();

    app(Despachador::class)->ofrecerReserva(reservaBuscando(['destino_direccion' => 'Tribunales']), $chofer);

    expect($this->push->titulosPara($chofer))->toBe(['Solicitud de reserva para 02/10 12:00'])
        ->and($this->push->enviados[0]['cuerpo'])->toBe('Hacia Tribunales. Respondé antes del 01/10 09:30.')
        ->and($this->push->enviados[0]['datos'])->toMatchArray(['tipo' => 'oferta_reserva']);
});

it('confirma la reserva al solicitante con la fecha y hora', function () {
    sinJobsDiferidos();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaBuscando();

    app(Asignador::class)->asignarReserva($viaje, $chofer);

    expect($this->push->titulosPara($viaje->solicitante))->toBe(['Reserva confirmada para 02/10 12:00'])
        ->and($this->push->titulosPara($chofer))->toBe([]);
});

it('avisa al chofer de una reserva obligatoria asignada', function () {
    sinJobsDiferidos();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaBuscando(['obligatorio' => true]);

    app(Asignador::class)->asignarReserva($viaje, $chofer);

    expect($this->push->titulosPara($chofer))->toBe(['Reserva asignada'])
        ->and($this->push->titulosPara($viaje->solicitante))->toBe(['Reserva confirmada para 02/10 12:00']);
});

it('avisa al solicitante que elija otro chofer si rechazan su reserva', function () {
    sinJobsDiferidos();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $viaje = reservaBuscando();
    $d = app(Despachador::class);
    $d->ofrecerReserva($viaje, Usuario::factory()->chofer()->create());

    $d->responder(OfertaViaje::sole(), false);

    expect($this->push->titulosPara($viaje->solicitante))->toBe(['Tu reserva no fue aceptada']);
});

it('avisa al solicitante si el chofer cancela su reserva', function () {
    sinJobsDiferidos();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($chofer, Carbon::parse('2026-10-02 15:00'));

    app(ServicioViaje::class)->cancelarPorChofer($viaje, $chofer, 'Turno médico');

    expect($this->push->titulosPara($viaje->solicitante))->toBe(['Tu chofer canceló la reserva']);
});

it('avisa al chofer si el solicitante cancela la reserva', function () {
    sinJobsDiferidos();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($chofer, Carbon::parse('2026-10-02 15:00'));

    app(ServicioViaje::class)->cancelarPorSolicitante($viaje, $viaje->solicitante, null);

    expect($this->push->titulosPara($chofer))->toBe(['Reserva cancelada']);
});
