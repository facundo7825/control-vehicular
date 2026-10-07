<?php

namespace App\Mapas;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Búsqueda con Nominatim: por defecto la API pública de OpenStreetMap (solo desarrollo/demos) o, con
 * `LUGARES_NOMINATIM_URL`, un servidor propio. Con un servidor propio no hay espera ni lock entre pedidos
 * (sí el cache y el corte ante falla) y la app puede autocompletar.
 *
 * Política de uso del Nominatim público: User-Agent identificable, como mucho 1 pedido por segundo, cache y nada de
 * autocompletar (la app solo busca al confirmar el texto: ver `lugares_autocompletar` en /api/configuracion).
 * Un lock en cache serializa los pedidos y, si el anterior fue hace menos de un segundo, se ESPERA lo que
 * falte (la consulta no se descarta). Las consultas repetidas salen del cache (24 h, por texto normalizado,
 * zona y servidor) sin esperar ni llamar. Si un pedido falla, durante 60 s todas las búsquedas devuelven [] al
 * instante, para no ocupar el servidor esperando a un servicio caído o que nos bloqueó. Si el lock no se
 * consigue en 1 s (mucha demanda, o un lock que quedó colgado tras cortar el proceso), esa búsqueda
 * devuelve [] sin cortar: no se retiene el único worker de `artisan serve`.
 *
 * Privacidad: la ubicación se redondea a 1 decimal (~11 km) antes de enviarla o usarla en la clave del cache.
 *
 * También hace la geocodificación inversa (`/reverse`) con el mismo servidor, User-Agent, espera, corte y cache
 * (24 h por punto redondeado a 4 decimales, ~11 m), pero con un plazo total (2 s, o uno compartido entre los dos
 * puntos de un viaje) que incluye el lock y la espera; si no alcanza, no pide.
 */
class BuscadorNominatim implements BuscadorLugares, GeocodificadorInverso
{
    public const URL_PUBLICA = 'https://nominatim.openstreetmap.org';

    private const HOST_PUBLICO = 'nominatim.openstreetmap.org';

    private const CLAVE_ULTIMO = 'lugares:nominatim:ultimo';

    private const CLAVE_CORTE = 'lugares:nominatim:corte';

    private const CLAVE_AVISO_URL = 'lugares:nominatim:aviso-url';

    private const SEGUNDOS_CACHE = 86400;

    private const SEGUNDOS_CORTE = 60;

    private const TIMEOUT_SEG = 3;

    /**
     * Tolerancia al decidir si el timeout de una inversa se achicó por el plazo (lock, espera o un punto anterior).
     * Un timeout que achicamos nosotros no es una falla del servidor: no activa el corte.
     */
    private const MARGEN_RECORTE_SEG = 0.1;

    /** Medio lado, en grados (~22 km), de la caja con la que se sesga la búsqueda. */
    private const MARGEN_GRADOS = 0.2;

    /** @var Closure(int): void */
    private Closure $dormir;

    private string $url;

    private bool $publica;

    /**
     * @param  (Closure(int): void)|null  $dormir  espera en microsegundos; se inyecta en los tests.
     * @param  string  $url  base del servidor (sin `/search`): el público o uno propio.
     */
    public function __construct(private string $userAgent, ?Closure $dormir = null, string $url = self::URL_PUBLICA)
    {
        $this->dormir = $dormir ?? function (int $micro): void {
            usleep($micro);
        };
        $url = trim($url) ?: self::URL_PUBLICA;
        $this->url = rtrim($url, '/');
        $this->publica = self::esPublica($url);

        $partes = parse_url($url);
        $valida = is_array($partes) && in_array(strtolower($partes['scheme'] ?? ''), ['http', 'https'], true)
            && ($partes['host'] ?? '') !== '';
        if (! $valida && Cache::add(self::CLAVE_AVISO_URL, true, 86400)) {
            // Es configuración, no una consulta: alcanza con decir qué revisar.
            Log::warning('LUGARES_NOMINATIM_URL no tiene esquema y host (p. ej. http://127.0.0.1:8088): se usa tal cual como servidor propio');
        }
    }

    /**
     * Si la URL es la del servidor público de OpenStreetMap, el único con límite de pedidos y sin autocompletar.
     * Vacía también cuenta como pública: es la que se usa por defecto.
     */
    public static function esPublica(string $url): bool
    {
        $url = trim($url);

        return $url === '' || strtolower((string) parse_url($url, PHP_URL_HOST)) === self::HOST_PUBLICO;
    }

    /** Si está activo el corte de 60 s tras una falla (servicio caído o que nos bloqueó). */
    public static function cortado(): bool
    {
        return Cache::has(self::CLAVE_CORTE);
    }

    public function buscar(string $texto, ?float $lat, ?float $lng): array
    {
        $texto = trim($texto);
        $conZona = $lat !== null && $lng !== null;
        $lat = $conZona ? round($lat, 1) : null;
        $lng = $conZona ? round($lng, 1) : null;
        $clave = 'lugares:nominatim:'.md5(mb_strtolower($texto).'|'.($conZona ? "$lat,$lng" : '').'|'.$this->url);

        $guardado = Cache::get($clave);
        if (is_array($guardado)) {
            return $guardado;
        }
        if (Cache::has(self::CLAVE_CORTE)) {
            return [];
        }

        $lugares = $this->consultar('/search', $this->parametrosBusqueda($texto, $lat, $lng), fn (array $filas) => $this->mapear($filas));
        if ($lugares !== null) {
            Cache::put($clave, $lugares, self::SEGUNDOS_CACHE);
        }

        return $lugares ?? [];
    }

    public function direccion(float $lat, float $lng, ?float $hasta = null): ?string
    {
        $hasta ??= microtime(true) + self::PLAZO_SEG;
        $lat = round($lat, 4);
        $lng = round($lng, 4);
        $clave = 'lugares:nominatim:inverso:'.md5("$lat,$lng|".$this->url);

        // Se guarda envuelta: un punto sin dirección (`direccion` null) también sale del cache.
        $guardado = Cache::get($clave);
        if (is_array($guardado)) {
            return $guardado['direccion'];
        }
        if (Cache::has(self::CLAVE_CORTE)) {
            return null;
        }

        $resultado = $this->consultar('/reverse', [
            'format' => 'jsonv2',
            'lat' => $lat,
            'lon' => $lng,
            'zoom' => 18,
            'addressdetails' => 1,
            'accept-language' => 'es',
        ], fn (array $fila) => ['direccion' => self::armarDireccion($fila)], $hasta);
        if ($resultado !== null) {
            Cache::put($clave, $resultado, self::SEGUNDOS_CACHE);
        }

        return $resultado['direccion'] ?? null;
    }

    /**
     * Dirección legible de una respuesta de `/reverse`: "calle altura, barrio" ("Sarmiento 520, Centro"); sin barrio,
     * la localidad ("Belgrano 40, Andalgalá"). Sin altura, "calle, barrio o localidad". Sin calle, el nombre del
     * lugar o la localidad. Null si no hay nada de eso (por ejemplo `{"error": "Unable to geocode"}`).
     */
    public static function armarDireccion(array $fila): ?string
    {
        $partes = is_array($fila['address'] ?? null) ? $fila['address'] : [];
        $primera = function (array $claves) use ($partes): ?string {
            foreach ($claves as $c) {
                $valor = is_scalar($partes[$c] ?? null) ? trim((string) $partes[$c]) : '';
                if ($valor !== '') {
                    return $valor;
                }
            }

            return null;
        };

        $calle = $primera(['road', 'pedestrian']);
        $altura = $primera(['house_number']);
        $barrio = $primera(['suburb', 'neighbourhood']);
        $localidad = $primera(['city', 'town', 'village', 'hamlet', 'municipality']);
        $nombre = is_scalar($fila['name'] ?? null) ? trim((string) $fila['name']) : '';

        $direccion = match (true) {
            $calle !== null => implode(', ', array_filter([$altura !== null ? "$calle $altura" : $calle, $barrio ?? $localidad])),
            $nombre !== '' => $nombre,
            default => $localidad ?? $barrio,
        };

        return $direccion === null ? null : mb_substr($direccion, 0, 255);
    }

    private function parametrosBusqueda(string $texto, ?float $lat, ?float $lng): array
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

        return $params;
    }

    /**
     * Un pedido al servidor, con la espera y el lock del público.
     *
     * @template T of array
     *
     * @param  Closure(array): T  $mapear  convierte el JSON de una respuesta exitosa.
     * @param  ?float  $hasta  plazo total (microtime) de una inversa: si antes de pedir (contando el lock y la
     *                         espera) quedaría menos de PLAZO_MINIMO_SEG, no se pide. Sin él, 3 s de timeout.
     * @return ?T null si falló o si no alcanzó el plazo.
     */
    private function consultar(string $ruta, array $params, Closure $mapear, ?float $hasta = null): ?array
    {
        $alcanza = fn (float $espera = 0.0) => $hasta === null
            || $hasta - microtime(true) - $espera >= self::PLAZO_MINIMO_SEG;

        try {
            if (! $alcanza()) {
                return null;
            }
            if (! $this->publica) {
                return $this->pedir($ruta, $params, $mapear, $hasta);
            }

            return Cache::lock('lugares:nominatim:lock', 15)->block(1, function () use ($ruta, $params, $mapear, $hasta, $alcanza) {
                if (Cache::has(self::CLAVE_CORTE)) {
                    return null;
                }
                $ultimo = (float) Cache::get(self::CLAVE_ULTIMO, 0.0);
                $falta = max(0.0, 1.0 - (microtime(true) - $ultimo));
                // Sin plazo para esperar y pedir: no se pide, y tampoco se marca un pedido que no se hizo.
                if (! $alcanza($falta)) {
                    return null;
                }
                if ($falta > 0) {
                    ($this->dormir)((int) ceil($falta * 1_000_000));
                }

                try {
                    return $this->pedir($ruta, $params, $mapear, $hasta);
                } finally {
                    Cache::put(self::CLAVE_ULTIMO, microtime(true), 60);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Nominatim: no se pudo consultar', ['error' => $e::class]);

            return null;
        }
    }

    /**
     * Con plazo, el timeout es lo que queda de él (como mucho PLAZO_SEG). Si lo achicamos nosotros (el lock, la
     * espera o un punto anterior se comieron parte), quedarse sin respuesta no es culpa del servidor y no corta.
     *
     * @return ?array null si falló (y corta, salvo un timeout achicado).
     */
    private function pedir(string $ruta, array $params, Closure $mapear, ?float $hasta): ?array
    {
        $queda = $hasta === null ? null : $hasta - microtime(true);
        $timeout = $queda === null ? self::TIMEOUT_SEG : round(min(self::PLAZO_SEG, $queda), 2);
        $achicado = $queda !== null && $queda < self::PLAZO_SEG - self::MARGEN_RECORTE_SEG;

        try {
            $r = Http::timeout($timeout)->withUserAgent($this->userAgent)->get($this->url.$ruta, $params);
        } catch (ConnectionException $e) {
            // Nunca el mensaje: lleva la URL, con el texto buscado o el punto.
            Log::warning('Nominatim sin conexión', ['error' => $e::class, 'timeout_achicado' => $achicado]);
            if (! $achicado) {
                $this->cortar();
            }

            return null;
        }

        if (! $r->successful() || ! is_array($r->json())) {
            Log::warning('Nominatim falló', ['http' => $r->status()]);
            $this->cortar();

            return null;
        }

        return $mapear($r->json());
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
