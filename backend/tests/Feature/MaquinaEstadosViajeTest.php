<?php

use App\Enums\EstadoViaje as E;
use App\Excepciones\TransicionInvalida;
use App\Models\Viaje;
use App\Servicios\MaquinaEstadosViaje;

it('permite las transiciones del flujo', function (E $desde, E $hacia) {
    expect(app(MaquinaEstadosViaje::class)->puede($desde, $hacia))->toBeTrue();
})->with([
    [E::Buscando, E::Ofrecido], [E::Buscando, E::Aceptado], [E::Buscando, E::SinChofer],
    [E::Ofrecido, E::Buscando], [E::Ofrecido, E::Aceptado], [E::Ofrecido, E::SinChofer],
    [E::Aceptado, E::EnCamino], [E::EnCamino, E::Llego], [E::Llego, E::EnCurso],
    [E::EnCurso, E::Finalizado], [E::Aceptado, E::Buscando], [E::Llego, E::Cancelado],
    [E::Aceptado, E::SinChofer],
]);

it('prohíbe transiciones fuera del flujo', function (E $desde, E $hacia) {
    expect(app(MaquinaEstadosViaje::class)->puede($desde, $hacia))->toBeFalse();
})->with([
    [E::Buscando, E::EnCurso], [E::EnCurso, E::Cancelado], [E::Finalizado, E::Buscando],
    [E::Cancelado, E::Aceptado], [E::SinChofer, E::Aceptado], [E::Aceptado, E::Finalizado],
]);

it('aplica estado, atributos y marca de tiempo', function () {
    $viaje = Viaje::factory()->create();

    $cambio = app(MaquinaEstadosViaje::class)->transicionar($viaje, E::Aceptado, ['motivo' => 'Otro']);

    $fresco = $viaje->fresh();
    expect($cambio)->toBeTrue()
        ->and($fresco->estado)->toBe(E::Aceptado)
        ->and($fresco->motivo)->toBe('Otro')
        ->and($fresco->aceptado_en)->not->toBeNull();
});

it('es idempotente si el viaje ya está en el estado destino', function () {
    $viaje = Viaje::factory()->create(['estado' => E::Finalizado, 'finalizado_en' => now()->subHour()]);

    expect(app(MaquinaEstadosViaje::class)->transicionar($viaje, E::Finalizado))->toBeFalse()
        ->and($viaje->fresh()->finalizado_en->lt(now()->subMinutes(30)))->toBeTrue();
});

it('rechaza una transición inválida sin modificar el viaje', function () {
    $viaje = Viaje::factory()->create(['estado' => E::EnCurso]);

    expect(fn () => app(MaquinaEstadosViaje::class)->transicionar($viaje, E::Cancelado))
        ->toThrow(TransicionInvalida::class);
    expect($viaje->fresh()->estado)->toBe(E::EnCurso);
});
