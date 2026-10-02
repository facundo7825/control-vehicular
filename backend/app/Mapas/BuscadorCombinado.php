<?php

namespace App\Mapas;

/**
 * Varios buscadores en orden (`LUGARES_DRIVER=nominatim,georef`): une sus resultados, descarta los que quedan a
 * menos de 30 m de uno ya elegido y devuelve como mucho 5. Si ya hay 5, no consulta los siguientes. Cada buscador
 * mantiene su propio cache y su corte ante falla; como ninguno lanza, uno caído no frena a los demás.
 */
class BuscadorCombinado implements BuscadorLugares
{
    private const METROS_DUPLICADO = 30;

    private const MAXIMO = 5;

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
        foreach ($this->buscadores as $buscador) {
            foreach ($buscador->buscar($texto, $lat, $lng) as $nuevo) {
                if (! $this->repetido($lugares, $nuevo)) {
                    $lugares[] = $nuevo;
                }
                if (count($lugares) >= self::MAXIMO) {
                    return $lugares;
                }
            }
        }

        return $lugares;
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
