<?php

namespace App\Mapas;

use Illuminate\Support\Facades\Log;

/**
 * Varios buscadores en orden (`LUGARES_DRIVER=nominatim,georef`): une sus resultados, descarta los que quedan a
 * menos de 30 m de uno ya elegido y devuelve como mucho 5. Para que el primero no tape a los siguientes, de cada
 * buscador con otros por detrás se toman como mucho 3; si al final quedan lugares libres, se completan con lo que
 * sobró, en orden. Cada buscador mantiene su propio cache y su corte ante falla; si uno lanza igual, se anota en el
 * log solo su clase y se sigue con los demás.
 */
class BuscadorCombinado implements BuscadorLugares
{
    private const METROS_DUPLICADO = 30;

    private const MAXIMO = 5;

    /** Cuántos se toman de un buscador mientras quedan otros por consultar. */
    private const MAXIMO_POR_BUSCADOR = 3;

    /** @param  list<BuscadorLugares>  $buscadores */
    public function __construct(private array $buscadores) {}

    /**
     * Los drivers de `LUGARES_DRIVER`, que puede ser uno o una lista separada por comas ("nominatim,georef").
     *
     * @return non-empty-list<string>
     */
    public static function drivers(string $config): array
    {
        $drivers = array_values(array_filter(array_map('trim', explode(',', strtolower($config))), fn (string $d) => $d !== ''));

        return $drivers ?: [''];
    }

    public function buscar(string $texto, ?float $lat, ?float $lng): array
    {
        $lugares = [];
        $sobrantes = [];
        $ultimo = count($this->buscadores) - 1;

        foreach ($this->buscadores as $i => $buscador) {
            if (count($lugares) >= self::MAXIMO) {
                break;
            }
            $tope = $i < $ultimo ? min(self::MAXIMO, count($lugares) + self::MAXIMO_POR_BUSCADOR) : self::MAXIMO;

            foreach ($this->consultar($buscador, $texto, $lat, $lng) as $nuevo) {
                if ($this->repetido($lugares, $nuevo)) {
                    continue;
                }
                if (count($lugares) < $tope) {
                    $lugares[] = $nuevo;
                } else {
                    $sobrantes[] = $nuevo;
                }
            }
        }

        foreach ($sobrantes as $nuevo) {
            if (count($lugares) >= self::MAXIMO) {
                break;
            }
            if (! $this->repetido($lugares, $nuevo)) {
                $lugares[] = $nuevo;
            }
        }

        return $lugares;
    }

    /** @return list<array{nombre: string, direccion: string, lat: float, lng: float}> */
    private function consultar(BuscadorLugares $buscador, string $texto, ?float $lat, ?float $lng): array
    {
        try {
            return $buscador->buscar($texto, $lat, $lng);
        } catch (\Throwable $e) {
            // Nunca el mensaje: puede llevar el texto buscado o la URL.
            Log::warning('Búsqueda de lugares: un buscador falló', ['buscador' => $buscador::class, 'error' => $e::class]);

            return [];
        }
    }

    private function repetido(array $lugares, array $nuevo): bool
    {
        foreach ($lugares as $l) {
            if (Distancia::metros($l['lat'], $l['lng'], $nuevo['lat'], $nuevo['lng']) < self::METROS_DUPLICADO) {
                return true;
            }
        }

        return false;
    }
}
