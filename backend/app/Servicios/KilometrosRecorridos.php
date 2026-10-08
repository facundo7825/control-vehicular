<?php

namespace App\Servicios;

use App\Mapas\Distancia;
use App\Models\PuntoRecorrido;
use Illuminate\Contracts\Database\Query\Builder as BuilderContrato;

/**
 * Distancia recorrida de verdad en cada viaje: haversine entre los puntos consecutivos de recorrido_viaje.
 * Se calcula una vez, al finalizar el viaje (o si llegan puntos tarde), y queda en viajes.metros_recorridos.
 */
final class KilometrosRecorridos
{
    /**
     * Una sola consulta recorrida con cursor, ordenada por viaje, momento e id. Los viajes sin al menos dos
     * puntos no figuran en el resultado.
     *
     * @param  list<int>|BuilderContrato  $viajeIds  ids o una subconsulta que los selecciona
     * @return array<int, float> viaje_id => metros
     */
    public static function metrosPorViaje(array|BuilderContrato $viajeIds): array
    {
        $metros = [];
        $anterior = null;

        // Solo los puntos dentro del intervalo real del viaje [iniciado_en, finalizado_en]: con una acción
        // enviada tarde (sin señal), el viaje pudo terminar antes de puntos que el servidor ya había recibido.
        PuntoRecorrido::query()
            ->join('viajes', 'viajes.id', '=', 'recorrido_viaje.viaje_id')
            ->whereIn('recorrido_viaje.viaje_id', $viajeIds)
            ->where(fn ($q) => $q->whereNull('viajes.iniciado_en')
                ->orWhereColumn('recorrido_viaje.registrado_en', '>=', 'viajes.iniciado_en'))
            ->where(fn ($q) => $q->whereNull('viajes.finalizado_en')
                ->orWhereColumn('recorrido_viaje.registrado_en', '<=', 'viajes.finalizado_en'))
            ->orderBy('recorrido_viaje.viaje_id')->orderBy('recorrido_viaje.registrado_en')->orderBy('recorrido_viaje.id')
            ->toBase()
            ->select(['recorrido_viaje.viaje_id', 'recorrido_viaje.lat', 'recorrido_viaje.lng'])
            ->cursor()
            ->each(function (object $punto) use (&$metros, &$anterior) {
                $viaje = (int) $punto->viaje_id;
                $actual = ['viaje_id' => $viaje, 'lat' => (float) $punto->lat, 'lng' => (float) $punto->lng];
                if ($anterior !== null && $anterior['viaje_id'] === $viaje) {
                    $metros[$viaje] = ($metros[$viaje] ?? 0.0)
                        + Distancia::metros($anterior['lat'], $anterior['lng'], $actual['lat'], $actual['lng']);
                }
                $anterior = $actual;
            });

        return $metros;
    }

    /** Metros del recorrido de un viaje, redondeados; 0 si tiene menos de dos puntos. */
    public static function metrosDe(int $viajeId): int
    {
        return (int) round(self::metrosPorViaje([$viajeId])[$viajeId] ?? 0);
    }
}
