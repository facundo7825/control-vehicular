<?php

use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;

it('persiste un viaje con enums casteados', function () {
    $viaje = Viaje::factory()->create();

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Buscando)
        ->and($viaje->fresh()->tipo)->toBe(TipoViaje::Inmediato)
        ->and($viaje->solicitante->rol)->toBe(RolUsuario::Solicitante);
});

it('encuentra el turno abierto del chofer', function () {
    $chofer = Usuario::factory()->chofer()->create();
    Turno::factory()->for($chofer, 'chofer')->create(['fin' => now()->subHour()]);
    $abierto = Turno::factory()->for($chofer, 'chofer')->create(['fin' => null]);

    expect($chofer->turnoAbierto->id)->toBe($abierto->id);
});

it('no considera activa una reserva aceptada que es a futuro', function () {
    $chofer = Usuario::factory()->chofer()->create();
    Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'estado' => EstadoViaje::Aceptado,
        'tipo' => TipoViaje::Reserva,
        'programado_para' => now()->addDay(),
    ]);
    $inmediato = Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'estado' => EstadoViaje::EnCamino,
    ]);

    expect(Viaje::activosDeChofer($chofer->id)->pluck('id')->all())->toBe([$inmediato->id]);
});
