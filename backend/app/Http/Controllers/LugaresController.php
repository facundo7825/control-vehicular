<?php

namespace App\Http\Controllers;

use App\Mapas\BuscadorLugares;
use App\Mapas\GeocodificadorInverso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LugaresController extends Controller
{
    public function __invoke(Request $request, BuscadorLugares $buscador): JsonResponse
    {
        $datos = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:200'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ]);

        return response()->json($buscador->buscar(
            $datos['q'],
            isset($datos['lat']) ? (float) $datos['lat'] : null,
            isset($datos['lng']) ? (float) $datos['lng'] : null,
        ));
    }

    /** GET /api/lugares/inverso: la dirección de un punto, o null si no se pudo obtener. */
    public function inverso(Request $request, GeocodificadorInverso $geocodificador): JsonResponse
    {
        $datos = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(['direccion' => $geocodificador->direccion((float) $datos['lat'], (float) $datos['lng'])]);
    }
}
