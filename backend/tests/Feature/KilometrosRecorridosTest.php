<?php

use App\Enums\EstadoViaje;
use App\Mapas\Distancia;
use App\Models\PuntoRecorrido;
use App\Models\Viaje;
use App\Servicios\KilometrosRecorridos;
use App\Servicios\MaquinaEstadosViaje;
use Illuminate\Support\Carbon;

/** Carga los puntos en el orden dado ([lat, lng, registrado_en]). */
function cargarPuntos(Viaje $viaje, array $puntos): void
{
    foreach ($puntos as [$lat, $lng, $momento]) {
        PuntoRecorrido::create(['viaje_id' => $viaje->id, 'lat' => $lat, 'lng' => $lng, 'registrado_en' => Carbon::parse($momento)]);
    }
}

it('suma por haversine los metros de cada viaje, ordenando por momento aunque se hayan cargado al revés', function () {
    $a = Viaje::factory()->create();
    $b = Viaje::factory()->create();
    $sinPuntos = Viaje::factory()->create();
    $otro = Viaje::factory()->create(); // no se pide: no cuenta
    cargarPuntos($a, [[-34.610, -58.385, '10:02'], [-34.605, -58.380, '10:01'], [-34.600, -58.380, '10:00']]);
    cargarPuntos($b, [[-34.630, -58.400, '11:01'], [-34.620, -58.400, '11:00']]);
    cargarPuntos($otro, [[-34.0, -58.0, '09:00'], [-35.0, -58.0, '09:01']]);

    $metros = KilometrosRecorridos::metrosPorViaje([$a->id, $b->id, $sinPuntos->id]);

    expect(array_keys($metros))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($metros[$a->id])->toEqualWithDelta(
            Distancia::metros(-34.600, -58.380, -34.605, -58.380) + Distancia::metros(-34.605, -58.380, -34.610, -58.385), 0.001)
        ->and($metros[$b->id])->toEqualWithDelta(Distancia::metros(-34.620, -58.400, -34.630, -58.400), 0.001);
});

it('no une puntos de viajes distintos aunque se hayan cargado intercalados y en el mismo momento', function () {
    // Un mismo viaje no puede repetir momento (índice único viaje_id + registrado_en); viajes distintos sí.
    $a = Viaje::factory()->create();
    $b = Viaje::factory()->create();
    cargarPuntos($b, [[-34.700, -58.500, '10:00']]);
    cargarPuntos($a, [[-34.600, -58.380, '10:00']]);
    cargarPuntos($b, [[-34.710, -58.500, '10:01']]);
    cargarPuntos($a, [[-34.610, -58.380, '10:01']]);

    $metros = KilometrosRecorridos::metrosPorViaje([$a->id, $b->id]);

    expect($metros[$a->id])->toEqualWithDelta(Distancia::metros(-34.600, -58.380, -34.610, -58.380), 0.001)
        ->and($metros[$b->id])->toEqualWithDelta(Distancia::metros(-34.700, -58.500, -34.710, -58.500), 0.001);
});

it('acepta una subconsulta de ids', function () {
    $viaje = Viaje::factory()->create(['motivo' => 'medible']);
    cargarPuntos($viaje, [[-34.600, -58.380, '10:00'], [-34.610, -58.380, '10:01']]);

    expect(KilometrosRecorridos::metrosPorViaje(Viaje::where('motivo', 'medible')->select('id')))
        ->toHaveKey($viaje->id);
});

it('sin viajes devuelve vacío', function () {
    expect(KilometrosRecorridos::metrosPorViaje([]))->toBe([]);
});

it('al finalizar un viaje guarda los metros de su recorrido', function () {
    // Los puntos (10:00 y 10:01) tienen que caer dentro del viaje: los km cuentan solo su intervalo.
    $this->travelTo(Carbon::parse('11:00'));
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::EnCurso, 'iniciado_en' => now()->subHour()]);
    $sinPuntos = Viaje::factory()->create(['estado' => EstadoViaje::EnCurso, 'iniciado_en' => now()->subHour()]);
    cargarPuntos($viaje, [[-34.600, -58.380, '10:00'], [-34.610, -58.380, '10:01']]);

    $maquina = app(MaquinaEstadosViaje::class);
    $maquina->transicionar($viaje, EstadoViaje::Finalizado);
    $maquina->transicionar($sinPuntos, EstadoViaje::Finalizado);

    expect($viaje->fresh()->metros_recorridos)->toBe((int) round(Distancia::metros(-34.600, -58.380, -34.610, -58.380)))
        ->and($sinPuntos->fresh()->metros_recorridos)->toBe(0);
});

it('la migración rellena los viajes finalizados y deja sin dato los que ya no tienen recorrido', function () {
    $migracion = require database_path('migrations/2026_10_01_000001_agregar_metros_recorridos_a_viajes.php');
    $migracion->down();

    $finalizado = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()]);
    $unPunto = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()]);
    $purgado = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()->subYear()]);
    $enCurso = Viaje::factory()->create(['estado' => EstadoViaje::EnCurso, 'iniciado_en' => now()]);
    cargarPuntos($finalizado, [[-34.600, -58.380, '10:00'], [-34.610, -58.380, '10:01']]);
    cargarPuntos($unPunto, [[-34.600, -58.380, '10:00']]);
    cargarPuntos($enCurso, [[-34.600, -58.380, '10:00'], [-34.610, -58.380, '10:01']]);

    $migracion->up();

    expect($finalizado->fresh()->metros_recorridos)->toBe((int) round(Distancia::metros(-34.600, -58.380, -34.610, -58.380)))
        ->and($unPunto->fresh()->metros_recorridos)->toBe(0)
        ->and($purgado->fresh()->metros_recorridos)->toBeNull()
        ->and($enCurso->fresh()->metros_recorridos)->toBeNull();
});
