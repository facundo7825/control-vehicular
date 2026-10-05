<?php

namespace App\Mapas;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tiempos de manejo con OSRM, sin servicios pagos: un servidor propio en producción (la misma URL que los
 * recorridos; por defecto, el público de demostración, solo para desarrollo/demos).
 *
 * - duracionesHacia usa el servicio `table` (cada origen como fuente, el destino como único destino);
 * - duracionRuta usa `route` sin geometría y se cachea 10 min (sirve para las reservas, sin choferes que se muevan).
 *
 * Como los otros drivers remotos, nunca lanza: sin dato devuelve null. Si el servicio falla, durante 60 s
 * devuelve null al instante sin llamarlo, y quien consume conserva su respaldo (el asignador, el orden en
 * línea recta). Las coordenadas van redondeadas a 5 decimales (~1 m) y en el log no van coordenadas ni URL.
 */
class ServicioMapasOsrm implements ServicioMapas
{
    private const SEGUNDOS_CACHE_RUTA = 600;

    private const SEGUNDOS_CORTE = 60;

    private const CLAVE_CORTE = 'mapas:osrm:corte';

    private ClienteOsrm $osrm;

    /** @param  (Closure(int): void)|null  $dormir  espera en microsegundos; se inyecta en los tests. */
    public function __construct(string $userAgent, string $url, ?Closure $dormir = null)
    {
        $this->osrm = new ClienteOsrm($userAgent, $url, $dormir);
    }

    public function duracionesHacia(array $origenes, float $lat, float $lng): array
    {
        if ($origenes === []) {
            return [];
        }

        $claves = array_keys($origenes);
        $n = count($claves);

        $json = $this->pedir(
            'table',
            [...array_values($origenes), [$lat, $lng]],
            ['sources' => implode(';', range(0, $n - 1)), 'destinations' => $n, 'annotations' => 'duration'],
            fn (array $j) => is_array($j['durations'] ?? null) && count($j['durations']) === $n
                && array_is_list($j['durations']) && array_filter($j['durations'], 'is_array') === $j['durations'],
        );

        $resultado = [];
        foreach ($claves as $i => $clave) {
            $segundos = $json['durations'][$i][0] ?? null;
            $resultado[$clave] = is_numeric($segundos) ? (int) round((float) $segundos) : null;
        }

        return $resultado;
    }

    public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
    {
        $puntos = [[$oLat, $oLng], [$dLat, $dLng]];
        $clave = 'mapas:osrm:ruta:'.md5(self::coordenadas($puntos));

        $guardado = Cache::get($clave);
        if (is_int($guardado)) {
            return $guardado;
        }

        $json = $this->pedir('route', $puntos, ['overview' => 'false'],
            fn (array $j) => is_numeric($j['routes'][0]['duration'] ?? null));
        if ($json === null) {
            return null;
        }

        $segundos = (int) round((float) $json['routes'][0]['duration']);
        Cache::put($clave, $segundos, self::SEGUNDOS_CACHE_RUTA);

        return $segundos;
    }

    /**
     * Consulta {servicio}/v1/driving/{lng,lat;...}.
     *
     * @param  list<array{0: float, 1: float}>  $puntos  [lat, lng]
     * @param  Closure(array): bool  $valida  ¿la respuesta "Ok" trae lo esperado?
     * @return array|null la respuesta; null sin dato (sin ruta posible, en corte, lock tomado o falla, que corta 60 s).
     */
    private function pedir(string $servicio, array $puntos, array $query, Closure $valida): ?array
    {
        if ($this->enCorte()) {
            return null;
        }

        try {
            $r = $this->osrm->get(
                "$servicio/v1/driving/".self::coordenadas($puntos),
                $query,
                fn () => $this->enCorte(),
            );
            if ($r === null) {
                return null; // En corte o con el lock del servidor público tomado: sin dato, sin cortar.
            }

            $json = $r->json();
            $code = is_array($json) ? ($json['code'] ?? null) : null;
            if (in_array($code, ['NoRoute', 'NoSegment', 'NoTable'], true)) {
                return null;
            }
            if (! $r->successful() || $code !== 'Ok' || ! $valida($json)) {
                Log::warning('OSRM (tiempos) falló', ['servicio' => $servicio, 'http' => $r->status(), 'code' => is_string($code) ? $code : null]);

                return $this->cortar();
            }

            return $json;
        } catch (ConnectionException $e) {
            // Nunca el mensaje: lleva la URL, con las coordenadas.
            Log::warning('OSRM (tiempos) sin conexión', ['servicio' => $servicio, 'error' => $e::class]);

            return $this->cortar();
        } catch (\Throwable $e) {
            Log::warning('OSRM (tiempos): respuesta inesperada', ['servicio' => $servicio, 'error' => $e::class]);

            return $this->cortar();
        }
    }

    /** "lng,lat;lng,lat;..." con 5 decimales como mucho. */
    private static function coordenadas(array $puntos): string
    {
        return implode(';', array_map(fn (array $p) => round((float) $p[1], 5).','.round((float) $p[0], 5), $puntos));
    }

    private function enCorte(): bool
    {
        return Cache::has(self::CLAVE_CORTE);
    }

    private function cortar(): null
    {
        Cache::put(self::CLAVE_CORTE, true, self::SEGUNDOS_CORTE);

        return null;
    }
}
