<?php

/*
 * Proceso independiente que ejecuta una acción de negocio contra la base MySQL/MariaDB,
 * esperando hasta un instante común (barrera) para que varios procesos choquen a la vez.
 *
 * Uso: php proceso_carrera.php <accion> '<json args>' <barrera unix float>
 * Imprime una línea JSON: {"ok":true,"resultado":...} o {"ok":false,"error":"..."}
 */

use App\Enums\EstadoViaje;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\Asignador;
use App\Servicios\Despachador;
use App\Servicios\ServicioAsistencia;
use App\Servicios\ServicioTurnos;
use App\Servicios\ServicioViaje;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $accion, $json, $barrera] = $argv;
$a = json_decode($json, true);

while (microtime(true) < (float) $barrera) {
    usleep(100);
}

try {
    $resultado = match ($accion) {
        'asignar' => app(Asignador::class)->asignar(Viaje::find($a['viaje']), Usuario::find($a['chofer'])),
        'cancelar_solicitante' => app(ServicioViaje::class)
            ->cancelarPorSolicitante($v = Viaje::find($a['viaje']), $v->solicitante, null)->estado->value,
        'avanzar' => app(ServicioViaje::class)
            ->avanzar(Viaje::find($a['viaje']), Usuario::find($a['chofer']), EstadoViaje::from($a['estado']))->estado->value,
        'pedir' => app(ServicioViaje::class)->pedir(Usuario::find($a['solicitante']), [
            'modo' => 'mas_cercano',
            'origen_lat' => -34.60, 'origen_lng' => -58.38,
            'destino_lat' => -34.61, 'destino_lng' => -58.39,
        ])->id,
        'finalizar_turno' => app(ServicioTurnos::class)->finalizar(Usuario::find($a['chofer']))->id,
        'iniciar_turno' => app(ServicioTurnos::class)->iniciar(Usuario::find($a['chofer']), $a['vehiculo'])->id,
        'fichar' => app(ServicioAsistencia::class)
            ->procesar($a['id_externo'], $a['tipo'], null, $a['id_evento'] ?? null)['resultado'],
        'reasignar_admin' => app(ServicioViaje::class)
            ->reasignarPorAdmin(Viaje::find($a['viaje']), Usuario::find($a['chofer']))->estado->value,
        'aceptar_oferta' => (function () use ($a) {
            app(Despachador::class)->responder(OfertaViaje::find($a['oferta']), true);

            return OfertaViaje::find($a['oferta'])->viaje->estado->value;
        })(),
    };
    echo json_encode(['ok' => true, 'resultado' => $resultado]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]);
}
