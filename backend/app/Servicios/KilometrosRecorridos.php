<?php

namespace App\Servicios;

use App\Mapas\Distancia;
use App\Models\PuntoRecorrido;
use Illuminate\Contracts\Database\Query\Builder as BuilderContrato;

/** Distancia recorrida de verdad en cada viaje: haversine entre los puntos consecutivos de recorrido_viaje. */
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

        PuntoRecorrido::query()
            ->whereIn('viaje_id', $viajeIds)
            ->orderBy('viaje_id')->orderBy('registrado_en')->orderBy('id')
            ->toBase()
            ->select(['viaje_id', 'lat', 'lng'])
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
}
