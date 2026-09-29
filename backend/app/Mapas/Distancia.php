<?php

namespace App\Mapas;

final class Distancia
{
    private const RADIO_TIERRA_M = 6371000;

    /** Distancia en línea recta (haversine) en metros. */
    public static function metros(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::RADIO_TIERRA_M * asin(min(1, sqrt($a)));
    }
}
