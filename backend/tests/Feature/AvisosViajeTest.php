<?php

use App\Enums\EstadoViaje;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\Despachador;
use App\Servicios\MaquinaEstadosViaje;
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
