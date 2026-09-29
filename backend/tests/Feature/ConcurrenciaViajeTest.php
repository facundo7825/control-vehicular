<?php

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Events\ViajeActualizado;
use App\Excepciones\TransicionInvalida;
use App\Models\OfertaViaje;
use App\Models\Viaje;
use App\Servicios\Asignador;
use App\Servicios\Despachador;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

// Simulan carreras entregando al servicio una copia del viaje leída antes de que otro request lo cambiara.

beforeEach(fn () => Queue::fake());

it('si el chofer acepta justo antes de que el solicitante cancele, el chofer recibe la cancelación', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Ofrecido]);
    $copiaDelSolicitante = Viaje::find($viaje->id);

    app(Asignador::class)->asignar(Viaje::find($viaje->id), $chofer);
    Event::fake([ViajeActualizado::class]);
    app(ServicioViaje::class)->cancelarPorSolicitante($copiaDelSolicitante, $viaje->solicitante, null);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado);
    Event::assertDispatched(ViajeActualizado::class, fn ($e) => collect($e->broadcastOn())
        ->contains(fn ($canal) => $canal->name === "private-chofer.{$chofer->id}"));
});

it('un viaje cancelado no revive si el chofer avanza con datos viejos', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado, 'aceptado_en' => now(),
    ]);
    $copiaDelChofer = Viaje::find($viaje->id);

    app(ServicioViaje::class)->cancelarPorSolicitante(Viaje::find($viaje->id), $viaje->solicitante, null);

    expect(fn () => app(ServicioViaje::class)->avanzar($copiaDelChofer, $chofer, EstadoViaje::EnCamino))
        ->toThrow(TransicionInvalida::class);
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado);
});

it('el chofer no puede cancelar con datos viejos un viaje que el solicitante ya canceló', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado, 'aceptado_en' => now(),
    ]);
    $copiaDelChofer = Viaje::find($viaje->id);

    app(ServicioViaje::class)->cancelarPorSolicitante(Viaje::find($viaje->id), $viaje->solicitante, null);

    expect(fn () => app(ServicioViaje::class)->cancelarPorChofer($copiaDelChofer, $chofer, 'Pinché una goma'))
        ->toThrow(App\Excepciones\ReglaNegocio::class);
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado)
        ->and(OfertaViaje::count())->toBe(0);
});

it('buscar chofer para un viaje ya cancelado no falla ni lo marca sin_chofer', function () {
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Buscando]);
    $copia = Viaje::find($viaje->id);
    app(ServicioViaje::class)->cancelarPorSolicitante(Viaje::find($viaje->id), $viaje->solicitante, null);

    app(Despachador::class)->pedirA($copia, choferEnTurno());

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado);
});

it('al cancelar un viaje ofrecido se avisa al chofer que tenía la oferta', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['origen_lat' => -34.60, 'origen_lng' => -58.38]);
    app(Despachador::class)->despachar($viaje);
    expect(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Pendiente);

    Event::fake([ViajeActualizado::class]);
    app(ServicioViaje::class)->cancelarPorSolicitante($viaje->fresh(), $viaje->solicitante, null);

    Event::assertDispatched(ViajeActualizado::class, fn ($e) => collect($e->broadcastOn())
        ->contains(fn ($canal) => $canal->name === "private-chofer.{$chofer->id}"));
});
