<?php

namespace App\Http\Controllers;

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
            // Si la app puede buscar lugares mientras se escribe. La política de Nominatim prohíbe autocompletar
            // (ahí se busca solo al confirmar); Google sí lo admite y el buscador falso (desarrollo y tests) también.
            'lugares_autocompletar' => in_array(config('vehiculos.lugares.driver'), ['google', 'falso'], true),
        ]);
    }
}
