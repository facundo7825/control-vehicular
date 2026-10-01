<?php

namespace App\Http\Controllers;

use App\Mapas\BuscadorLugares;
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
}
