<?php

use App\Enums\EstadoViaje;
use App\Mapas\Distancia;
use App\Models\PuntoRecorrido;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\MaquinaEstadosViaje;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

it('interpreta registrado_en sin offset como hora de Buenos Aires', function () {
    $this->travelTo(Carbon::parse('2026-10-01 15:00:00')); // 12:00 en Buenos Aires
    $turno = Turno::factory()->create();
    UbicacionChofer::create([
        'chofer_id' => $turno->chofer_id, 'lat' => -34.61, 'lng' => -58.39, 'actualizado_en' => now()->subMinute(),
    ]);

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => -34.60, 'lng' => -58.38, 'registrado_en' => '2026-10-01T11:59:50'],
    ]])->assertNoContent();

    $u = UbicacionChofer::find($turno->chofer_id);
    expect($u->lat)->toBe(-34.60)
        ->and($u->actualizado_en->equalTo(now()->subSeconds(10)))->toBeTrue();
});

it('no duplica el recorrido si la app reenvía un lote', function () {
    $turno = Turno::factory()->create();
    $viaje = Viaje::factory()->create([
        'chofer_id' => $turno->chofer_id, 'estado' => EstadoViaje::EnCurso, 'iniciado_en' => now()->subMinutes(5),
    ]);
    // Como lo manda la app: UTC con milisegundos.
    $lote = ['puntos' => [
        ['lat' => -34.60, 'lng' => -58.30, 'registrado_en' => now()->subSeconds(20)->format('Y-m-d\TH:i:s.v\Z')],
        ['lat' => -34.61, 'lng' => -58.31, 'registrado_en' => now()->subSeconds(10)->format('Y-m-d\TH:i:s.v\Z')],
    ]];

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', $lote)->assertNoContent();
    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', $lote)->assertNoContent();

    expect(PuntoRecorrido::where('viaje_id', $viaje->id)->count())->toBe(2);

    // Un lote con lo viejo (sin confirmar) más lo nuevo, y un punto repetido dentro del mismo lote.
    $lote['puntos'][] = ['lat' => -34.62, 'lng' => -58.32, 'registrado_en' => now()->format('Y-m-d\TH:i:s.v\Z')];
    $lote['puntos'][] = end($lote['puntos']);
    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', $lote)->assertNoContent();

    expect(PuntoRecorrido::where('viaje_id', $viaje->id)->orderBy('registrado_en')->pluck('lat')->all())
        ->toBe([-34.60, -34.61, -34.62]);
});

it('el mismo momento en dos viajes distintos no choca', function () {
    $viajes = Viaje::factory()->count(2)->create();
    $momento = now()->subMinute();

    foreach ($viajes as $v) {
        PuntoRecorrido::insertOrIgnore([['viaje_id' => $v->id, 'lat' => 1, 'lng' => 1, 'registrado_en' => $momento]]);
    }

    expect(PuntoRecorrido::count())->toBe(2);
});

it('la migración del índice único deja un solo punto por viaje y momento', function () {
    $migracion = require database_path('migrations/2026_09_30_000001_indice_unico_recorrido_viaje.php');
    $migracion->down();

    $viaje = Viaje::factory()->create();
    $momento = now()->subMinute()->startOfSecond();
    DB::table('recorrido_viaje')->insert([
        ['viaje_id' => $viaje->id, 'lat' => 1, 'lng' => 1, 'registrado_en' => $momento],
        ['viaje_id' => $viaje->id, 'lat' => 1, 'lng' => 1, 'registrado_en' => $momento],
        ['viaje_id' => $viaje->id, 'lat' => 2, 'lng' => 2, 'registrado_en' => $momento->copy()->addSeconds(5)],
    ]);

    $migracion->up();

    expect(PuntoRecorrido::where('viaje_id', $viaje->id)->count())->toBe(2);
    expect(fn () => PuntoRecorrido::create(['viaje_id' => $viaje->id, 'lat' => 3, 'lng' => 3, 'registrado_en' => $momento]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('si el viaje se finaliza mientras llegan puntos, recalcula los metros guardados', function () {
    $turno = Turno::factory()->create();
    $viaje = Viaje::factory()->create([
        'chofer_id' => $turno->chofer_id, 'estado' => EstadoViaje::EnCurso, 'iniciado_en' => now()->subMinutes(10),
    ]);
    PuntoRecorrido::create(['viaje_id' => $viaje->id, 'lat' => -34.600, 'lng' => -58.380, 'registrado_en' => now()->subMinutes(9)]);
    PuntoRecorrido::create(['viaje_id' => $viaje->id, 'lat' => -34.610, 'lng' => -58.380, 'registrado_en' => now()->subMinutes(8)]);

    // El chofer finaliza justo después de que el servidor encontró el viaje en curso y antes de guardar los puntos.
    $finalizado = false;
    DB::listen(function ($consulta) use (&$finalizado, $viaje) {
        if (! $finalizado && str_contains($consulta->sql, 'from "viajes"') && str_contains($consulta->sql, '"iniciado_en" <=')
            && ! str_contains($consulta->sql, 'for update')) {
            $finalizado = true;
            app(MaquinaEstadosViaje::class)->transicionar(Viaje::find($viaje->id), EstadoViaje::Finalizado);
        }
    });

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => -34.620, 'lng' => -58.380, 'registrado_en' => now()->subMinutes(7)->toIso8601String()],
    ]])->assertNoContent();

    $esperado = Distancia::metros(-34.600, -58.380, -34.610, -58.380)
        + Distancia::metros(-34.610, -58.380, -34.620, -58.380);
    expect($finalizado)->toBeTrue()
        ->and($viaje->fresh()->estado)->toBe(EstadoViaje::Finalizado)
        ->and($viaje->fresh()->metros_recorridos)->toBe((int) round($esperado));
});
