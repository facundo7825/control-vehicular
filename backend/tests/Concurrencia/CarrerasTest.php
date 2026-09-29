<?php

/*
 * Carreras reales entre procesos contra MySQL/MariaDB (en SQLite lockForUpdate no bloquea).
 * No corre con la suite normal. Requiere una base vacía dedicada:
 *
 *   CREATE DATABASE vehiculos_concurrencia;
 *   ./vendor/bin/pest tests/Concurrencia
 *
 * Conexión configurable con CONCURRENCIA_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD.
 */

use App\Enums\EstadoViaje;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

function conexionConcurrencia(): array
{
    return [
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => getenv('CONCURRENCIA_DB_HOST') ?: '127.0.0.1',
        'DB_PORT' => getenv('CONCURRENCIA_DB_PORT') ?: '3306',
        'DB_DATABASE' => getenv('CONCURRENCIA_DB_DATABASE') ?: 'vehiculos_concurrencia',
        'DB_USERNAME' => getenv('CONCURRENCIA_DB_USERNAME') ?: 'root',
        'DB_PASSWORD' => getenv('CONCURRENCIA_DB_PASSWORD') ?: '',
    ];
}

/**
 * Lanza un proceso por acción; todos arrancan en el mismo instante.
 *
 * @param  array<int, array{0: string, 1: array}>  $acciones
 * @return array<int, array{ok: bool, resultado?: mixed, error?: string}>
 */
function carrera(array $acciones): array
{
    $barrera = microtime(true) + 3; // margen para que todos los procesos terminen de arrancar
    $env = [
        ...conexionConcurrencia(),
        'APP_ENV' => 'testing',
        'QUEUE_CONNECTION' => 'sync',
        'BROADCAST_CONNECTION' => 'null',
        'CACHE_STORE' => 'array',
        'MAPAS_DRIVER' => 'falso',
        'NOTIFICACIONES_DRIVER' => 'registro',
        'LOG_CHANNEL' => 'null',
    ];

    $procesos = array_map(function (array $accion) use ($barrera, $env) {
        $p = new Process(
            [PHP_BINARY, __DIR__.'/proceso_carrera.php', $accion[0], json_encode($accion[1]), (string) $barrera],
            base_path(), $env, null, 60,
        );
        $p->start();

        return $p;
    }, $acciones);

    return array_map(function (Process $p) {
        $p->wait();
        $salida = json_decode(trim($p->getOutput()), true);
        expect($salida)->toBeArray("El actor no devolvió JSON:\n".$p->getOutput().$p->getErrorOutput());

        return $salida;
    }, $procesos);
}

beforeEach(function () {
    $c = conexionConcurrencia();
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => $c['DB_HOST'],
        'database.connections.mysql.port' => $c['DB_PORT'],
        'database.connections.mysql.database' => $c['DB_DATABASE'],
        'database.connections.mysql.username' => $c['DB_USERNAME'],
        'database.connections.mysql.password' => $c['DB_PASSWORD'],
    ]);
    DB::purge('mysql');

    try {
        DB::connection('mysql')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('MySQL/MariaDB no disponible: '.$e->getMessage());
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

it('diez viajes obligatorios compiten por un único chofer y solo uno lo obtiene', function () {
    $chofer = choferEnTurno();
    $viajes = Viaje::factory()->count(10)->create(['obligatorio' => true]);

    $r = carrera($viajes->map(fn ($v) => ['asignar', ['viaje' => $v->id, 'chofer' => $chofer->id]])->all());

    expect(collect($r)->where('resultado', true)->count())->toBe(1)
        ->and(Viaje::where('chofer_id', $chofer->id)->count())->toBe(1)
        ->and(Viaje::where('estado', EstadoViaje::Buscando)->count())->toBe(9);
});

it('un viaje cancelado nunca revive aunque el chofer avance al mismo tiempo', function () {
    foreach (range(1, 10) as $_) {
        $chofer = choferEnTurno();
        $viaje = Viaje::factory()->create([
            'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado, 'aceptado_en' => now(),
        ]);

        carrera([
            ['avanzar', ['viaje' => $viaje->id, 'chofer' => $chofer->id, 'estado' => 'en_camino']],
            ['cancelar_solicitante', ['viaje' => $viaje->id]],
        ]);

        $final = $viaje->fresh();
        // Si se canceló, tiene que haber quedado cancelado; si avanzó, la cancelación debió fallar.
        expect($final->cancelado_en === null || $final->estado === EstadoViaje::Cancelado)->toBeTrue(
            "Viaje {$final->id} quedó {$final->estado->value} con cancelado_en = {$final->cancelado_en}");
    }
});

it('una aceptación y una cancelación simultáneas dejan el viaje cancelado y consistente', function () {
    foreach (range(1, 10) as $_) {
        $chofer = choferEnTurno();
        $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Ofrecido]);

        carrera([
            ['asignar', ['viaje' => $viaje->id, 'chofer' => $chofer->id]],
            ['cancelar_solicitante', ['viaje' => $viaje->id]],
        ]);

        $final = $viaje->fresh();
        expect($final->estado)->toBe(EstadoViaje::Cancelado)
            ->and($final->cancelado_en)->not->toBeNull();
        // Si llegó a aceptarse antes de cancelar, aceptado_en quedó marcado junto con el chofer.
        expect($final->chofer_id === null || $final->aceptado_en !== null)->toBeTrue();
    }
});

it('cinco pedidos simultáneos del mismo solicitante crean un solo viaje', function () {
    $solicitante = Usuario::factory()->create();

    $r = carrera(array_fill(0, 5, ['pedir', ['solicitante' => $solicitante->id]]));

    expect(Viaje::where('solicitante_id', $solicitante->id)->count())->toBe(1)
        ->and(collect($r)->where('ok', true)->count())->toBe(1);
});

it('un chofer nunca queda fuera de turno con un viaje obligatorio asignado', function () {
    foreach (range(1, 10) as $_) {
        $chofer = choferEnTurno();
        $viaje = Viaje::factory()->create(['obligatorio' => true]);

        carrera([
            ['asignar', ['viaje' => $viaje->id, 'chofer' => $chofer->id]],
            ['finalizar_turno', ['chofer' => $chofer->id]],
        ]);

        $asignado = $viaje->fresh()->chofer_id === $chofer->id;
        $turnoCerrado = Turno::where('chofer_id', $chofer->id)->whereNull('fin')->doesntExist();
        expect($asignado && $turnoCerrado)->toBeFalse("El chofer {$chofer->id} cerró turno con el viaje {$viaje->id} asignado");
    }
});
