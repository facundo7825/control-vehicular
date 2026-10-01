<?php

namespace App\Mapas;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Recorridos con OSRM (por defecto, el servidor público de demostración: solo desarrollo/demos).
 *
 * Política de uso del servidor público: User-Agent identificable y como mucho 1 pedido por segundo. Igual que
 * en la búsqueda con Nominatim, un lock serializa los pedidos y, si el anterior fue hace menos de un segundo,
 * se espera lo que falte. Las indicaciones en castellano las arma {@see InstruccionesOsrm}.
 */
class RutasOsrm extends RutasRemotas
{
    private const CLAVE_ULTIMO = 'rutas:osrm:ultimo';

    /** @var Closure(int): void */
    private Closure $dormir;

    /** @param  (Closure(int): void)|null  $dormir  espera en microsegundos; se inyecta en los tests. */
    public function __construct(private string $userAgent, private string $url, ?Closure $dormir = null)
    {
        $this->url = rtrim($url, '/');
        $this->dormir = $dormir ?? function (int $micro): void {
            usleep($micro);
        };
    }

    protected function nombre(): string
    {
        return 'osrm';
    }

    protected function consultar(float $oLat, float $oLng, float $dLat, float $dLng): array|false|null
    {
        $url = "{$this->url}/route/v1/driving/$oLng,$oLat;$dLng,$dLat";

        try {
            $r = Cache::lock('rutas:osrm:lock', 10)->block(5, function () use ($url) {
                if ($this->enCorte()) {
                    return null;
                }
                $falta = 1.0 - (microtime(true) - (float) Cache::get(self::CLAVE_ULTIMO, 0.0));
                if ($falta > 0) {
                    ($this->dormir)((int) ceil($falta * 1_000_000));
                }

                try {
                    return Http::timeout(self::TIMEOUT_SEG)->withUserAgent($this->userAgent)
                        ->get($url, ['overview' => 'full', 'geometries' => 'geojson', 'steps' => 'true']);
                } finally {
                    Cache::put(self::CLAVE_ULTIMO, microtime(true), 60);
                }
            });
        } catch (LockTimeoutException) {
            return null; // Mucha demanda: esta vez sin recorrido, pero el servicio no falló.
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
