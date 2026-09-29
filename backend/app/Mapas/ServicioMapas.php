<?php

namespace App\Mapas;

interface ServicioMapas
{
    /**
     * Tiempo de manejo en segundos desde cada origen hasta el destino.
     *
     * @param  array<int|string, array{0: float, 1: float}>  $origenes  [clave => [lat, lng]]
     * @return array<int|string, ?int>  [clave => segundos | null si no hay dato]. Nunca lanza.
     */
    public function duracionesHacia(array $origenes, float $lat, float $lng): array;

    /** Duración de manejo en segundos entre dos puntos, o null si no hay dato. */
    public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int;
}
