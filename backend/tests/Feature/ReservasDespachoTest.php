<?php

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\ResultadoOferta;
use App\Excepciones\ReglaNegocio;
use App\Jobs\VencerOferta;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\Asignador;
use App\Servicios\Despachador;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
});

it('ofrece la reserva al chofer elegido por 30 minutos, aunque esté fuera de turno', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaBuscando();

    expect(app(Despachador::class)->ofrecerReserva($viaje, $chofer))->toBeTrue();

    $oferta = OfertaViaje::sole();
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Ofrecido)
        ->and($oferta->chofer_id)->toBe($chofer->id)
        ->and($oferta->resultado)->toBe(ResultadoOferta::Pendiente)
        ->and($oferta->vence_en->eq(now()->addMinutes(30)))->toBeTrue();
    Queue::assertPushed(VencerOferta::class, fn ($job) => $job->ofertaId === $oferta->id);
});

it('la oferta vence a más tardar una hora antes del viaje y dura al menos 5 minutos', function (int $minutosHastaElViaje, int $venceEnMinutos) {
    $viaje = reservaBuscando(['programado_para' => now()->addMinutes($minutosHastaElViaje)]);

    app(Despachador::class)->ofrecerReserva($viaje, Usuario::factory()->chofer()->create());

    expect(OfertaViaje::sole()->vence_en->eq(now()->addMinutes($venceEnMinutos)))->toBeTrue();
})->with([
    'viaje lejano: plazo normal' => [600, 30],
    'viaje en 80 minutos: una hora antes' => [80, 20],
    'viaje en 62 minutos: mínimo de 5' => [62, 5],
]);

it('no ofrece la reserva a un chofer con otra reserva en esa franja', function () {
    $chofer = Usuario::factory()->chofer()->create();
    reservaAceptada($chofer, now()->addDay()->setTime(15, 30));
    $viaje = reservaBuscando();

    expect(app(Despachador::class)->ofrecerReserva($viaje, $chofer))->toBeFalse()
        ->and($viaje->fresh()->estado)->toBe(EstadoViaje::Buscando)
        ->and(OfertaViaje::count())->toBe(0);
});

it('al aceptar asigna la reserva sin vehículo y sin exigir turno', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaBuscando();
    $d = app(Despachador::class);
    $d->ofrecerReserva($viaje, $chofer);

    $d->responder(OfertaViaje::sole(), true);

    $fresco = $viaje->fresh();
    expect($fresco->estado)->toBe(EstadoViaje::Aceptado)
        ->and($fresco->chofer_id)->toBe($chofer->id)
        ->and($fresco->vehiculo_id)->toBeNull()
        ->and($fresco->aceptado_en)->not->toBeNull()
        ->and(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Aceptada);
});

it('al aceptar vuelve a verificar la franja: de dos ofertas superpuestas solo se acepta una', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $primera = reservaBuscando();
    $segunda = reservaBuscando(['programado_para' => now()->addDay()->setTime(15, 20)]);
    $d = app(Despachador::class);
    $d->ofrecerReserva($primera, $chofer);
    $d->ofrecerReserva($segunda, $chofer);
    $d->responder(OfertaViaje::where('viaje_id', $primera->id)->sole(), true);

    expect(fn () => $d->responder(OfertaViaje::where('viaje_id', $segunda->id)->sole(), true))
        ->toThrow(ReglaNegocio::class, 'La reserva ya no está disponible o se superpone con otra de tu agenda.');
    expect($primera->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($segunda->fresh()->estado)->toBe(EstadoViaje::SinChofer)
        ->and($segunda->fresh()->chofer_id)->toBeNull();
});

it('una reserva rechazada queda sin chofer y nunca se despacha como inmediato', function () {
    choferEnTurno(); // hay un chofer libre que recibiría un pedido inmediato
    $viaje = reservaBuscando(['modo' => ModoViaje::CualquieraDisponible]);
    $d = app(Despachador::class);
    $d->ofrecerReserva($viaje, Usuario::factory()->chofer()->create());

    $d->responder(OfertaViaje::sole(), false);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer)
        ->and(OfertaViaje::count())->toBe(1);
});

it('una oferta de reserva vencida deja la reserva sin chofer', function () {
    choferEnTurno();
    $viaje = reservaBuscando(['modo' => ModoViaje::CualquieraDisponible]);
    $d = app(Despachador::class);
    $d->ofrecerReserva($viaje, Usuario::factory()->chofer()->create());

    (new VencerOferta(OfertaViaje::sole()->id))->handle($d);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer)
        ->and(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Expirada)
        ->and(OfertaViaje::count())->toBe(1);
});

it('asigna directo una reserva obligatoria sin crear oferta', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaBuscando(['obligatorio' => true]);

    expect(app(Asignador::class)->asignarReserva($viaje, $chofer))->toBeTrue()
        ->and($viaje->estado)->toBe(EstadoViaje::Aceptado)
        ->and($viaje->chofer_id)->toBe($chofer->id)
        ->and(OfertaViaje::count())->toBe(0);
});

it('no asigna por la vía de reservas una franja ocupada ni un viaje inmediato', function () {
    $chofer = Usuario::factory()->chofer()->create();
    reservaAceptada($chofer, now()->addDay()->setTime(14, 0)); // 14:00-15:00 + colchón choca con 15:00
    $asignador = app(Asignador::class);

    expect($asignador->asignarReserva(reservaBuscando(), $chofer))->toBeFalse()
        ->and($asignador->asignarReserva(Viaje::factory()->create(), $chofer))->toBeFalse();
});

it('una oferta de reserva pendiente no impide recibir pedidos inmediatos', function () {
    $chofer = choferEnTurno(-34.601, -58.381);
    $d = app(Despachador::class);
    $d->ofrecerReserva(reservaBuscando(), $chofer);
    $inmediato = Viaje::factory()->create(['origen_lat' => -34.600, 'origen_lng' => -58.380]);

    $d->despachar($inmediato);

    expect(OfertaViaje::where('viaje_id', $inmediato->id)->sole()->chofer_id)->toBe($chofer->id);
});

it('asignar con una copia vieja no revive una reserva que el solicitante canceló', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaBuscando(['obligatorio' => true]);
    $copia = Viaje::find($viaje->id);
    app(ServicioViaje::class)->cancelarPorSolicitante(Viaje::find($viaje->id), $viaje->solicitante, null);

    expect(app(Asignador::class)->asignarReserva($copia, $chofer))->toBeFalse()
        ->and($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado)
        ->and($viaje->fresh()->chofer_id)->toBeNull();
});
