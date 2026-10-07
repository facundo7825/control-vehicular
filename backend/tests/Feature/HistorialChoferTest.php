<?php

use App\Enums\EstadoViaje;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;

function viajeDelChofer(Usuario $chofer, EstadoViaje $estado, array $attrs = []): Viaje
{
    return Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'estado' => $estado,
        'aceptado_en' => now()->subHour(),
        ...$attrs,
    ]);
}

beforeEach(fn () => $this->travelTo('2026-10-07 15:00:00')); // 12:00 hora local (UTC-3)

it('devuelve solo los viajes del chofer, finalizados y cancelados', function () {
    $chofer = choferEnTurno();
    $propio = viajeDelChofer($chofer, EstadoViaje::Finalizado, ['finalizado_en' => now()]);
    $cancelado = viajeDelChofer($chofer, EstadoViaje::Cancelado, ['cancelado_en' => now()->subMinute()]);
    viajeDelChofer($chofer, EstadoViaje::EnCurso);
    viajeDelChofer($chofer, EstadoViaje::Aceptado);
    viajeDelChofer(choferEnTurno(), EstadoViaje::Finalizado, ['finalizado_en' => now()]);

    $this->actingAs($chofer)->getJson('/api/chofer/viajes')
        ->assertOk()
        ->assertJsonCount(2, 'viajes')
        ->assertJsonPath('viajes.0.id', $propio->id)
        ->assertJsonPath('viajes.0.solicitante.id', $propio->solicitante_id)
        ->assertJsonPath('viajes.1.id', $cancelado->id);
});

it('ordena del más reciente al más antiguo y limita a 50', function () {
    $chofer = choferEnTurno();
    $viejo = viajeDelChofer($chofer, EstadoViaje::Finalizado, ['finalizado_en' => now()->subDays(3)]);
    $cancelado = viajeDelChofer($chofer, EstadoViaje::Cancelado, ['cancelado_en' => now()->subDay()]);
    $reciente = viajeDelChofer($chofer, EstadoViaje::Finalizado, ['finalizado_en' => now()->subHour()]);

    $this->actingAs($chofer)->getJson('/api/chofer/viajes')
        ->assertOk()
        ->assertJsonPath('viajes.0.id', $reciente->id)
        ->assertJsonPath('viajes.1.id', $cancelado->id)
        ->assertJsonPath('viajes.2.id', $viejo->id);

    Viaje::factory()->count(52)->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()->subDays(10),
    ]);

    $this->actingAs($chofer)->getJson('/api/chofer/viajes')->assertJsonCount(50, 'viajes');
});

it('resume el día local: el borde cae a las 03:00 UTC', function () {
    $chofer = choferEnTurno();
    viajeDelChofer($chofer, EstadoViaje::Finalizado, ['finalizado_en' => '2026-10-07 02:00:00', 'metros_recorridos' => 9000]); // ayer 23:00 local
    viajeDelChofer($chofer, EstadoViaje::Finalizado, ['finalizado_en' => '2026-10-07 03:00:00', 'metros_recorridos' => 1500]); // hoy 00:00 local
    viajeDelChofer($chofer, EstadoViaje::Finalizado, ['finalizado_en' => '2026-10-07 14:00:00', 'metros_recorridos' => 2500]);
    viajeDelChofer($chofer, EstadoViaje::Finalizado, ['finalizado_en' => '2026-10-08 03:00:00', 'metros_recorridos' => 7000]); // mañana local
    viajeDelChofer($chofer, EstadoViaje::Cancelado, ['cancelado_en' => '2026-10-07 10:00:00', 'metros_recorridos' => 800]);
    viajeDelChofer(choferEnTurno(), EstadoViaje::Finalizado, ['finalizado_en' => '2026-10-07 10:00:00', 'metros_recorridos' => 4000]);

    $this->actingAs($chofer)->getJson('/api/chofer/viajes')
        ->assertOk()
        ->assertJsonPath('hoy.viajes', 2)
        ->assertJsonPath('hoy.metros', 4000);
});

it('cuenta los metros nulos como 0 y devuelve ceros sin viajes', function () {
    $chofer = choferEnTurno();

    $this->actingAs($chofer)->getJson('/api/chofer/viajes')
        ->assertOk()
        ->assertJsonPath('hoy.viajes', 0)
        ->assertJsonPath('hoy.metros', 0)
        ->assertJsonPath('viajes', []);

    viajeDelChofer($chofer, EstadoViaje::Finalizado, ['finalizado_en' => '2026-10-07 14:00:00', 'metros_recorridos' => null]);
    viajeDelChofer($chofer, EstadoViaje::Finalizado, ['finalizado_en' => '2026-10-07 13:00:00', 'metros_recorridos' => 1200]);

    $this->actingAs($chofer)->getJson('/api/chofer/viajes')
        ->assertJsonPath('hoy.viajes', 2)
        ->assertJsonPath('hoy.metros', 1200);
});

it('informa desde cuándo está en turno, o null sin turno abierto', function () {
    $turno = Turno::factory()->create(['inicio' => '2026-10-07 11:30:00']);

    $this->actingAs($turno->chofer)->getJson('/api/chofer/viajes')
        ->assertOk()
        ->assertJsonPath('hoy.en_turno_desde', fn ($v) => str_starts_with($v, '2026-10-07T11:30:00'));

    $turno->update(['fin' => now()]);

    $this->actingAs($turno->chofer->fresh())->getJson('/api/chofer/viajes')
        ->assertOk()
        ->assertJsonPath('hoy.en_turno_desde', null);
});

it('rechaza con 403 a quien no es chofer', function () {
    $this->actingAs(Usuario::factory()->create())->getJson('/api/chofer/viajes')->assertForbidden();
    $this->actingAs(Usuario::factory()->admin()->create())->getJson('/api/chofer/viajes')->assertForbidden();
});
