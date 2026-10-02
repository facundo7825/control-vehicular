<?php

namespace App\Http\Controllers;

use App\Mapas\BuscadorCombinado;
use App\Mapas\BuscadorNominatim;
use App\Servicios\Parametros;
use Illuminate\Http\JsonResponse;

class ConfiguracionController extends Controller
{
    public function __invoke(Parametros $p): JsonResponse
    {
        return response()->json([
            'gps_turno_seg' => $p->entero('gps_turno_seg'),
            'gps_viaje_seg' => $p->entero('gps_viaje_seg'),
            'oferta_segundos' => $p->entero('oferta_segundos'),
            'lugares_autocompletar' => $this->lugaresAutocompletar(),
        ]);
    }

    /**
     * Si la app puede buscar lugares mientras se escribe. La política del Nominatim público prohíbe autocompletar
     * (ahí se busca solo al confirmar); un Nominatim propio, Georef, Google y el buscador falso (desarrollo y
     * tests) sí lo admiten. Con varios drivers, todos tienen que admitirlo. LUGARES_AUTOCOMPLETAR lo fuerza.
     */
    private function lugaresAutocompletar(): bool
    {
        $forzado = config('vehiculos.lugares.autocompletar');
        if ($forzado !== null && $forzado !== '') {
            return filter_var($forzado, FILTER_VALIDATE_BOOLEAN);
        }

        foreach (BuscadorCombinado::drivers((string) config('vehiculos.lugares.driver')) as $driver) {
            $admite = match ($driver) {
                'google', 'falso', 'georef' => true,
                'nominatim' => ! BuscadorNominatim::esPublica((string) config('vehiculos.lugares.nominatim_url')),
                default => false,
            };
            if (! $admite) {
                return false;
            }
        }

        return true;
    }
}
