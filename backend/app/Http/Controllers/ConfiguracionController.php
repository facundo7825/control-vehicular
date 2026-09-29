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
        ]);
    }
}
