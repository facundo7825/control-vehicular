<?php

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\ResultadoOferta;
use App\Excepciones\ReglaNegocio;
use App\Jobs\VencerOferta;
use App\Models\OfertaViaje;
use App\Models\Viaje;
use App\Servicios\Despachador;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function viajeEnOrigen(array $attrs = []): Viaje
{
    return Viaje::factory()->create(['origen_lat' => -34.600, 'origen_lng' => -58.380, ...$attrs]);
}

it('ofrece el viaje al chofer libre más cercano por 30 segundos', function () {
    $lejos = choferEnTurno(-34.700, -58.480);
    $cerca = choferEnTurno(-34.601, -58.381);
    $viaje = viajeEnOrigen();

    app(Despachador::class)->despachar($viaje);

    $oferta = OfertaViaje::sole();
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Ofrecido)
        ->and($oferta->chofer_id)->toBe($cerca->id)
        ->and($oferta->resultado)->toBe(ResultadoOferta::Pendiente)
        ->and((int) $oferta->ofrecido_en->diffInSeconds($oferta->vence_en))->toBe(30);
    Queue::assertPushed(VencerOferta::class, fn ($job) => $job->ofertaId === $oferta->id);
});

it('asigna directo un viaje obligatorio sin crear oferta', function () {
    $cerca = choferEnTurno(-34.601, -58.381);
    $viaje = viajeEnOrigen(['obligatorio' => true]);

    app(Despachador::class)->despachar($viaje);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($viaje->fresh()->chofer_id)->toBe($cerca->id)
        ->and(OfertaViaje::count())->toBe(0);
});

it('marca sin_chofer si no hay choferes libres', function () {
    $viaje = viajeEnOrigen();

    app(Despachador::class)->despachar($viaje);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer);
});

it('pasa al siguiente chofer al rechazar y nunca vuelve a ofrecerle al que rechazó', function () {
    $a = choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.620, -58.400);
    $viaje = viajeEnOrigen();
    $d = app(Despachador::class);

    $d->despachar($viaje);
    $d->responder(OfertaViaje::where('chofer_id', $a->id)->sole(), false);
    $d->responder(OfertaViaje::where('chofer_id', $b->id)->sole(), false);

    expect(OfertaViaje::where('chofer_id', $a->id)->count())->toBe(1)
        ->and(OfertaViaje::where('chofer_id', $b->id)->count())->toBe(1)
        ->and($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer);
});

it('asigna al aceptar la oferta', function () {
    $a = choferEnTurno(-34.601, -58.381);
    $viaje = viajeEnOrigen();
    $d = app(Despachador::class);
    $d->despachar($viaje);

    $d->responder(OfertaViaje::sole(), true);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($viaje->fresh()->chofer_id)->toBe($a->id)
        ->and(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Aceptada);
});

it('al vencer la oferta pasa al siguiente chofer', function () {
    choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.620, -58.400);
    $viaje = viajeEnOrigen();
    $d = app(Despachador::class);
    $d->despachar($viaje);

    (new VencerOferta(OfertaViaje::sole()->id))->handle($d);

    expect(OfertaViaje::where('resultado', ResultadoOferta::Expirada)->count())->toBe(1)
        ->and(OfertaViaje::where('resultado', ResultadoOferta::Pendiente)->sole()->chofer_id)->toBe($b->id);
});

it('el vencimiento no hace nada si el chofer ya aceptó', function () {
    choferEnTurno(-34.601, -58.381);
    choferEnTurno(-34.620, -58.400);
    $viaje = viajeEnOrigen();
    $d = app(Despachador::class);
    $d->despachar($viaje);
    $oferta = OfertaViaje::sole();
    $d->responder($oferta, true);

    (new VencerOferta($oferta->id))->handle($d);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($oferta->fresh()->resultado)->toBe(ResultadoOferta::Aceptada)
        ->and(OfertaViaje::count())->toBe(1);
});

it('no acepta responder una oferta ya respondida', function () {
    choferEnTurno(-34.601, -58.381);
    $d = app(Despachador::class);
    $d->despachar(viajeEnOrigen());
    $oferta = OfertaViaje::sole();
    $d->responder($oferta, false);

    $d->responder($oferta->fresh(), true);
})->throws(ReglaNegocio::class, 'La oferta ya no está vigente.');

it('rechaza aceptar una oferta vencida y sigue buscando', function () {
    choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.620, -58.400);
    $d = app(Despachador::class);
    $d->despachar(viajeEnOrigen());
    $oferta = OfertaViaje::sole();
    $this->travel(31)->seconds();

    expect(fn () => $d->responder($oferta, true))->toThrow(ReglaNegocio::class, 'La oferta venció.');
    expect(OfertaViaje::where('resultado', ResultadoOferta::Pendiente)->sole()->chofer_id)->toBe($b->id);
});

it('no ofrece a un chofer que ya tiene otra oferta pendiente', function () {
    $a = choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.620, -58.400);
    $d = app(Despachador::class);
    $d->despachar(viajeEnOrigen());
    $segundo = viajeEnOrigen();

    $d->despachar($segundo);

    expect(OfertaViaje::where('viaje_id', $segundo->id)->sole()->chofer_id)->toBe($b->id);
});

it('pide a un chofer específico y queda sin_chofer si rechaza', function () {
    choferEnTurno(-34.601, -58.381);
    $elegido = choferEnTurno(-34.700, -58.480);
    $viaje = viajeEnOrigen(['modo' => ModoViaje::Especifico]);
    $d = app(Despachador::class);

    $d->pedirA($viaje, $elegido);
    $d->responder(OfertaViaje::sole(), false);

    expect(OfertaViaje::sole()->chofer_id)->toBe($elegido->id)
        ->and($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer);
});

it('asigna directo a un chofer específico si el viaje es obligatorio', function () {
    $elegido = choferEnTurno();
    $viaje = viajeEnOrigen(['modo' => ModoViaje::Especifico, 'obligatorio' => true]);

    app(Despachador::class)->pedirA($viaje, $elegido);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($viaje->fresh()->chofer_id)->toBe($elegido->id);
});

it('el vencimiento no hace nada si el viaje fue cancelado', function () {
    choferEnTurno(-34.601, -58.381);
    choferEnTurno(-34.620, -58.400);
    $viaje = viajeEnOrigen();
    $d = app(Despachador::class);
    $d->despachar($viaje);
    $viaje->fresh()->update(['estado' => EstadoViaje::Cancelado]);

    $d->vencer(OfertaViaje::sole());

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado)
        ->and(OfertaViaje::count())->toBe(1);
});
