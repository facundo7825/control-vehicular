<?php

namespace App\Mapas;

/** Desarrollo y tests: una línea recta de origen a destino, a 30 km/h, con la salida y la llegada. */
class RutasFalso implements ServicioRutas
{
    private const METROS_POR_SEGUNDO = 8.33;

    public function ruta(float $oLat, float $oLng, float $dLat, float $dLng): ?array
    {
        [$oLat, $oLng, $dLat, $dLng] = array_map(fn (float $v) => round($v, 4), [$oLat, $oLng, $dLat, $dLng]);
        $metros = Distancia::metros($oLat, $oLng, $dLat, $dLng);

        return [
            'distancia_m' => (int) round($metros),
            'duracion_s' => (int) round($metros / self::METROS_POR_SEGUNDO),
            'puntos' => [[$oLat, $oLng], [$dLat, $dLng]],
            'pasos' => [
                ['instruccion' => 'Salí hacia el destino', 'distancia_m' => (int) round($metros), 'indice' => 0, 'lat' => $oLat, 'lng' => $oLng, 'tipo' => 'salida'],
                ['instruccion' => 'Llegaste a destino', 'distancia_m' => 0, 'indice' => 1, 'lat' => $dLat, 'lng' => $dLng, 'tipo' => 'llegada'],
            ],
        ];
    }
}
