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
 * Política de uso de Nominatim: User-Agent identificable, como mucho 1 pedido por segundo, cache y nada de
 * autocompletar (la app solo busca al confirmar el texto: ver `lugares_autocompletar` en /api/configuracion).
 * Un lock en cache serializa los pedidos y, si el anterior fue hace menos de un segundo, se ESPERA lo que
 * falte (la consulta no se descarta). Las consultas repetidas salen del cache (24 h, por texto normalizado
 * y zona) sin esperar ni llamar. Si un pedido falla, durante 60 s todas las búsquedas devuelven [] al
 * instante, para no ocupar el servidor esperando a un servicio caído o que nos bloqueó. Si el lock no se
 * consigue en 1 s (mucha demanda, o un lock que quedó colgado tras cortar el proceso), esa búsqueda
 * devuelve [] sin cortar: no se retiene el único worker de `artisan serve`.
 *
 * Privacidad: la ubicación se redondea a 1 decimal (~11 km) antes de enviarla o usarla en la clave del cache.
 */
class BuscadorNominatim implements BuscadorLugares
{
    private const URL = 'https://nominatim.openstreetmap.org/search';

    private const CLAVE_ULTIMO = 'lugares:nominatim:ultimo';

    private const CLAVE_CORTE = 'lugares:nominatim:corte';

    private const SEGUNDOS_CACHE = 86400;

    private const SEGUNDOS_CORTE = 60;

    private const TIMEOUT_SEG = 3;

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
        $lat = $conZona ? round($lat, 1) : null;
        $lng = $conZona ? round($lng, 1) : null;
        $clave = 'lugares:nominatim:'.md5(mb_strtolower($texto).'|'.($conZona ? "$lat,$lng" : ''));

        $guardado = Cache::get($clave);
        if (is_array($guardado)) {
            return $guardado;
        }
        if (Cache::has(self::CLAVE_CORTE)) {
            return [];
        }

        $lugares = $this->consultar($texto, $lat, $lng);
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
            $params['viewbox'] = implode(',', array_map(
                fn (float $v) => round($v, 1),
                [$lng - $m, $lat + $m, $lng + $m, $lat - $m],
            ));
        }

        try {
            return Cache::lock('lugares:nominatim:lock', 15)->block(1, function () use ($params) {
                if (Cache::has(self::CLAVE_CORTE)) {
                    return null;
                }
                $ultimo = (float) Cache::get(self::CLAVE_ULTIMO, 0.0);
                $falta = 1.0 - (microtime(true) - $ultimo);
                if ($falta > 0) {
                    ($this->dormir)((int) ceil($falta * 1_000_000));
                }

                try {
                    $r = Http::timeout(self::TIMEOUT_SEG)->withUserAgent($this->userAgent)->get(self::URL, $params);
                } catch (ConnectionException $e) {
                    // Nunca el mensaje: lleva la URL, con el texto buscado y la zona.
                    Log::warning('Nominatim sin conexión', ['error' => $e::class]);
                    $this->cortar();

                    return null;
                } finally {
                    Cache::put(self::CLAVE_ULTIMO, microtime(true), 60);
                }

                if (! $r->successful() || ! is_array($r->json())) {
                    Log::warning('Nominatim falló', ['http' => $r->status()]);
                    $this->cortar();

                    return null;
                }

                return $this->mapear($r->json());
            });
        } catch (\Throwable $e) {
            Log::warning('Nominatim: no se pudo consultar', ['error' => $e::class]);

            return null;
        }
    }

    private function cortar(): void
    {
        Cache::put(self::CLAVE_CORTE, true, self::SEGUNDOS_CORTE);
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
