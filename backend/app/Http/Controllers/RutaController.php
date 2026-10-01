<?php

namespace App\Http\Controllers;

use App\Mapas\ServicioRutas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RutaController extends Controller
{
    public function __invoke(Request $request, ServicioRutas $rutas): JsonResponse
    {
        $datos = $request->validate([
            'origen_lat' => ['required', 'numeric', 'between:-90,90'],
            'origen_lng' => ['required', 'numeric', 'between:-180,180'],
            'destino_lat' => ['required', 'numeric', 'between:-90,90'],
            'destino_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $ruta = $rutas->ruta(
            (float) $datos['origen_lat'], (float) $datos['origen_lng'],
            (float) $datos['destino_lat'], (float) $datos['destino_lng'],
        );

        // Sin recorrido: 200 con null, y la app sigue sin dibujarlo.
        return $ruta === null ? JsonResponse::fromJsonString('null') : response()->json($ruta);
    }
}
