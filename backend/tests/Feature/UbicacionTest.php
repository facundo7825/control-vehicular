<?php

use App\Enums\EstadoViaje;
use App\Models\PuntoRecorrido;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;

it('guarda la última ubicación de un lote', function () {
    $turno = Turno::factory()->create();

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => -34.61, 'lng' => -58.39, 'registrado_en' => now()->subSeconds(20)->toIso8601String()],
        ['lat' => -34.60, 'lng' => -58.38, 'rumbo' => 90, 'registrado_en' => now()->subSeconds(5)->toIso8601String()],
    ]])->assertNoContent();

    $u = UbicacionChofer::find($turno->chofer_id);
    expect($u->lat)->toBe(-34.60)->and($u->rumbo)->toBe(90.0);
});

it('ignora un lote más viejo que la ubicación guardada', function () {
    $turno = Turno::factory()->create();
    UbicacionChofer::create(['chofer_id' => $turno->chofer_id, 'lat' => 1, 'lng' => 1, 'actualizado_en' => now()]);

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => 2, 'lng' => 2, 'registrado_en' => now()->subMinute()->toIso8601String()],
    ]])->assertNoContent();

    expect(UbicacionChofer::find($turno->chofer_id)->lat)->toBe(1.0);
});

it('no acepta ubicación sin turno abierto', function () {
    $this->actingAs(Usuario::factory()->chofer()->create())->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => 2, 'lng' => 2, 'registrado_en' => now()->toIso8601String()],
    ]])->assertStatus(422);

    expect(UbicacionChofer::count())->toBe(0);
});

it('registra el recorrido durante un viaje en curso', function () {
    $turno = Turno::factory()->create();
    $viaje = Viaje::factory()->create([
        'chofer_id' => $turno->chofer_id, 'estado' => EstadoViaje::EnCurso, 'iniciado_en' => now()->subMinutes(5),
    ]);

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => -34.6, 'lng' => -58.3, 'registrado_en' => now()->subMinutes(10)->toIso8601String()],
        ['lat' => -34.7, 'lng' => -58.4, 'registrado_en' => now()->subMinute()->toIso8601String()],
    ]])->assertNoContent();

    expect(PuntoRecorrido::where('viaje_id', $viaje->id)->count())->toBe(1);
});

it('valida coordenadas', function () {
    $turno = Turno::factory()->create();

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => 120, 'lng' => 2, 'registrado_en' => now()->toIso8601String()],
    ]])->assertStatus(422)->assertJsonValidationErrors('puntos.0.lat');
});
