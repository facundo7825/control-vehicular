<?php

namespace App\Mapas;

/** Desarrollo y tests: estima 30 km/h en línea recta. */
class ServicioMapasFalso implements ServicioMapas
{
    private const METROS_POR_SEGUNDO = 8.33;

    public function duracionesHacia(array $origenes, float $lat, float $lng): array
    {
        return array_map(
            fn (array $o) => (int) round(Distancia::metros($o[0], $o[1], $lat, $lng) / self::METROS_POR_SEGUNDO),
            $origenes,
        );
    }

    public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
    {
        return $this->duracionesHacia(['r' => [$oLat, $oLng]], $dLat, $dLng)['r'];
    }
}
