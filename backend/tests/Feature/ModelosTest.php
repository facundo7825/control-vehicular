<?php

use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Models\Alerta;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Carbon;

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

it('considera activa una reserva en camino aunque su hora programada todavía no llegó', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $reserva = reservaAceptada($chofer, now()->addMinutes(40), attrs: ['estado' => EstadoViaje::EnCamino]);

    expect(Viaje::activosDeChofer($chofer->id)->pluck('id')->all())->toBe([$reserva->id]);
});

it('considera activa una reserva aceptada cuya hora ya llegó', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $reserva = reservaAceptada($chofer, now()->subMinute());

    expect(Viaje::activosDeChofer($chofer->id)->pluck('id')->all())->toBe([$reserva->id]);
});

it('no considera activos los viajes terminados', function () {
    $chofer = Usuario::factory()->chofer()->create();
    foreach ([EstadoViaje::Finalizado, EstadoViaje::Cancelado, EstadoViaje::SinChofer] as $estado) {
        Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => $estado]);
    }

    expect(Viaje::activosDeChofer($chofer->id)->exists())->toBeFalse();
});

it('muestra la hora programada en la zona horaria de los usuarios', function () {
    $viaje = Viaje::factory()->create(['programado_para' => Carbon::parse('2026-10-01 15:05:00')]);

    expect($viaje->fresh()->horaProgramadaLocal())->toBe('01/10 12:05')
        ->and(Viaje::factory()->create()->horaProgramadaLocal())->toBeNull();
});

it('guarda alertas pendientes para el panel', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = Viaje::factory()->create();

    $alerta = Alerta::create([
        'tipo' => Alerta::RESERVA_SIN_TURNO,
        'viaje_id' => $viaje->id,
        'chofer_id' => $chofer->id,
        'mensaje' => 'Sin turno',
    ]);
    Alerta::create(['tipo' => 'otra', 'mensaje' => 'Resuelta', 'resuelta_en' => now()]);

    expect($alerta->fresh()->resuelta_en)->toBeNull()
        ->and($alerta->chofer->id)->toBe($chofer->id)
        ->and($alerta->viaje->id)->toBe($viaje->id)
        ->and(Alerta::pendientes()->pluck('id')->all())->toBe([$alerta->id]);
});
