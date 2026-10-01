<?php

namespace App\Mapas;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Base de los drivers que consultan un servicio externo, igual que la búsqueda de lugares: nunca lanza,
 * redondea las coordenadas a 4 decimales (~11 m) para el pedido y la clave del cache, cachea 10 min y, si
 * el servicio falla, durante 60 s devuelve null al instante sin llamarlo. En el log no van coordenadas ni URL.
 */
abstract class RutasRemotas implements ServicioRutas
{
    private const SEGUNDOS_CACHE = 600;

    private const SEGUNDOS_CORTE = 60;

    protected const TIMEOUT_SEG = 3;

    /** Nombre corto del driver, para las claves del cache y el log. */
    abstract protected function nombre(): string;

    /**
     * Consulta el servicio con las coordenadas ya redondeadas.
     *
     * @return array|false|null el recorrido; null si no hay recorrido posible (ni se cachea ni corta);
     *                          false si el servicio falló (corta 60 s).
     */
    abstract protected function consultar(float $oLat, float $oLng, float $dLat, float $dLng): array|false|null;

    public function ruta(float $oLat, float $oLng, float $dLat, float $dLng): ?array
    {
        $coords = array_map(fn (float $v) => round($v, 4), [$oLat, $oLng, $dLat, $dLng]);
        $clave = 'rutas:'.$this->nombre().':'.md5(implode(',', $coords));

        $guardado = Cache::get($clave);
        if (is_array($guardado)) {
            return $guardado;
        }
        if ($this->enCorte()) {
            return null;
        }

        try {
            $ruta = $this->consultar(...$coords);
        } catch (\Throwable $e) {
            // Nunca el mensaje: puede llevar la URL, con coordenadas (y la clave de Google).
            Log::warning('Rutas: respuesta inesperada', ['driver' => $this->nombre(), 'error' => $e::class]);
            $ruta = false;
        }

        if ($ruta === false) {
            Cache::put($this->claveCorte(), true, self::SEGUNDOS_CORTE);

            return null;
        }
        if ($ruta !== null) {
            Cache::put($clave, $ruta, self::SEGUNDOS_CACHE);
        }

        return $ruta;
    }

    protected function enCorte(): bool
    {
        return Cache::has($this->claveCorte());
    }

    private function claveCorte(): string
    {
        return 'rutas:'.$this->nombre().':corte';
    }
}
