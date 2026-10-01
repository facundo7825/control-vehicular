<?php

namespace App\Mapas;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Búsqueda con la API pública de OpenStreetMap. Solo para desarrollo/demos.
 *
 * Política de uso de Nominatim: User-Agent identificable, como mucho 1 pedido por segundo y cache.
 * Un lock en cache serializa los pedidos y, si el anterior fue hace menos de un segundo, se ESPERA lo que
 * falte (la consulta no se descarta). Las consultas repetidas salen del cache (24 h, por texto normalizado
 * y zona) sin esperar ni llamar.
 */
class BuscadorNominatim implements BuscadorLugares
{
    private const URL = 'https://nominatim.openstreetmap.org/search';

    private const CLAVE_ULTIMO = 'lugares:nominatim:ultimo';

    private const SEGUNDOS_CACHE = 86400;

    /** Medio lado, en grados (~22 km), de la caja con la que se sesga la búsqueda. */
    private const MARGEN_GRADOS = 0.2;

    /** @var Closure(int): void */
    private Closure $dormir;

    /** @param  (Closure(int): void)|null  $dormir  espera en microsegundos; se inyecta en los tests. */
    public function __construct(private string $userAgent, ?Closure $dormir = null)
    {
        $this->dormir = $dormir ?? function (int $micro): void {
            usleep($micro);
        };
    }

    public function buscar(string $texto, ?float $lat, ?float $lng): array
    {
        $texto = trim($texto);
        $conZona = $lat !== null && $lng !== null;
        $zona = $conZona ? round($lat, 1).','.round($lng, 1) : '';
        $clave = 'lugares:nominatim:'.md5(mb_strtolower($texto).'|'.$zona);

        $guardado = Cache::get($clave);
        if (is_array($guardado)) {
            return $guardado;
        }

        $lugares = $this->consultar($texto, $conZona ? $lat : null, $conZona ? $lng : null);
        if ($lugares !== null) {
            Cache::put($clave, $lugares, self::SEGUNDOS_CACHE);
        }

        return $lugares ?? [];
    }

    /** @return ?list<array{nombre: string, direccion: string, lat: float, lng: float}> null si falló. */
    private function consultar(string $texto, ?float $lat, ?float $lng): ?array
    {
        $params = [
            'q' => $texto,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => 5,
            'countrycodes' => 'ar',
            'accept-language' => 'es',
        ];
        if ($lat !== null && $lng !== null) {
            $m = self::MARGEN_GRADOS;
            // viewbox: izquierda,arriba,derecha,abajo (lon,lat,lon,lat). Sesga, no restringe.
            $params['viewbox'] = implode(',', [$lng - $m, $lat + $m, $lng + $m, $lat - $m]);
        }

        try {
            return Cache::lock('lugares:nominatim:lock', 15)->block(10, function () use ($params) {
                $ultimo = (float) Cache::get(self::CLAVE_ULTIMO, 0.0);
                $falta = 1.0 - (microtime(true) - $ultimo);
                if ($falta > 0) {
                    ($this->dormir)((int) ceil($falta * 1_000_000));
                }

                try {
                    $r = Http::timeout(5)->withUserAgent($this->userAgent)->get(self::URL, $params);
                } catch (ConnectionException $e) {
                    Log::warning('Nominatim sin conexión', ['error' => $e->getMessage()]);

                    return null;
                } finally {
                    Cache::put(self::CLAVE_ULTIMO, microtime(true), 60);
                }

                if ($r->failed() || ! is_array($r->json())) {
                    Log::warning('Nominatim falló', ['http' => $r->status()]);

                    return null;
                }

                return $this->mapear($r->json());
            });
        } catch (\Throwable $e) {
            Log::warning('Nominatim: no se pudo consultar', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return list<array{nombre: string, direccion: string, lat: float, lng: float}> */
    private function mapear(array $filas): array
    {
        $lugares = [];
        foreach (array_slice($filas, 0, 5) as $f) {
            if (! isset($f['lat'], $f['lon'], $f['display_name'])) {
                continue;
            }
            $direccion = (string) $f['display_name'];
            $nombre = trim((string) ($f['name'] ?? ''));
            if ($nombre === '') {
                $nombre = trim(explode(',', $direccion)[0]);
            }
            $lugares[] = [
                'nombre' => $nombre,
                'direccion' => $direccion,
                'lat' => (float) $f['lat'],
                'lng' => (float) $f['lon'],
            ];
        }

        return $lugares;
    }
}
