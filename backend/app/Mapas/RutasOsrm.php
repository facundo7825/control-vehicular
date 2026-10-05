<?php

namespace App\Mapas;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Recorridos con OSRM: un servidor propio en producción (por defecto, el público de demostración, solo para
 * desarrollo/demos). Los pedidos, con el límite de 1 por segundo del servidor público, los hace {@see ClienteOsrm};
 * si con el público el lock no se consigue en 1 s, esa vez no hay recorrido (sin cortar). Las indicaciones en
 * castellano las arma {@see InstruccionesOsrm}.
 */
class RutasOsrm extends RutasRemotas
{
    private ClienteOsrm $osrm;

    /** @param  (Closure(int): void)|null  $dormir  espera en microsegundos; se inyecta en los tests. */
    public function __construct(string $userAgent, string $url, ?Closure $dormir = null)
    {
        $this->osrm = new ClienteOsrm($userAgent, $url, $dormir);
    }

    protected function nombre(): string
    {
        return 'osrm';
    }

    protected function consultar(float $oLat, float $oLng, float $dLat, float $dLng): array|false|null
    {
        try {
            $r = $this->osrm->get(
                "route/v1/driving/$oLng,$oLat;$dLng,$dLat",
                ['overview' => 'full', 'geometries' => 'geojson', 'steps' => 'true'],
                fn () => $this->enCorte(),
            );
        } catch (ConnectionException $e) {
            // Nunca el mensaje: lleva la URL, con las coordenadas.
            Log::warning('OSRM sin conexión', ['error' => $e::class]);

            return false;
        }

        if ($r === null || in_array($r->json('code'), ['NoRoute', 'NoSegment'], true)) {
            return null;
        }
        if (! $r->successful() || $r->json('code') !== 'Ok' || ! is_array($r->json('routes.0'))) {
            Log::warning('OSRM falló', ['http' => $r->status(), 'code' => $r->json('code')]);

            return false;
        }

        return $this->mapear($r->json('routes.0'));
    }

    private function mapear(array $ruta): array
    {
        // GeoJSON viene como [lng, lat]; la respuesta va como [lat, lng].
        $puntos = array_map(
            fn (array $c) => [round((float) $c[1], 6), round((float) $c[0], 6)],
            $ruta['geometry']['coordinates'],
        );

        $pasos = [];
        $indice = 0;
        foreach ($ruta['legs'] as $tramo) {
            foreach ($tramo['steps'] as $paso) {
                $m = $paso['maneuver'];
                $lat = round((float) $m['location'][1], 6);
                $lng = round((float) $m['location'][0], 6);
                $indice = self::masCercano($puntos, $lat, $lng, $indice);
                $calle = trim((string) ($paso['name'] ?? '')) ?: trim((string) ($paso['ref'] ?? ''));
                $pasos[] = [
                    'instruccion' => InstruccionesOsrm::instruccion($m, $calle),
                    'distancia_m' => (int) round((float) $paso['distance']),
                    'indice' => $indice,
                    'lat' => $lat,
                    'lng' => $lng,
                    'tipo' => InstruccionesOsrm::tipo($m),
                ];
            }
        }

        return [
            'distancia_m' => (int) round((float) $ruta['distance']),
            'duracion_s' => (int) round((float) $ruta['duration']),
            'puntos' => $puntos,
            'pasos' => $pasos,
        ];
    }

    /** Índice del punto de $puntos más cercano a (lat, lng), buscando desde $desde hacia adelante. */
    private static function masCercano(array $puntos, float $lat, float $lng, int $desde): int
    {
        $coseno = cos(deg2rad($lat));
        $mejor = $desde;
        $mejorDistancia = INF;
        for ($i = $desde, $n = count($puntos); $i < $n; $i++) {
            $d = ($puntos[$i][0] - $lat) ** 2 + (($puntos[$i][1] - $lng) * $coseno) ** 2;
            if ($d < $mejorDistancia) {
                [$mejor, $mejorDistancia] = [$i, $d];
                if ($d == 0.0) {
                    break;
                }
            }
        }

        return $mejor;
    }
}
