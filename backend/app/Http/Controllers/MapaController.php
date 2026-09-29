<?php

namespace App\Http\Controllers;

use App\Servicios\CalculadorEstadoChofer;
use Illuminate\Http\JsonResponse;

class MapaController extends Controller
{
    public function __invoke(CalculadorEstadoChofer $estados): JsonResponse
    {
        return response()->json($estados->choferesEnTurno()->map(fn (array $f) => [
            'id' => $f['chofer']->id,
            'nombre' => $f['chofer']->nombre,
            'estado' => $f['estado']->value,
            'lat' => $f['chofer']->ubicacion?->lat,
            'lng' => $f['chofer']->ubicacion?->lng,
            'rumbo' => $f['chofer']->ubicacion?->rumbo,
            'actualizado_en' => $f['chofer']->ubicacion?->actualizado_en?->toIso8601String(),
            'vehiculo' => $f['chofer']->turnoAbierto->vehiculo->only(['patente', 'marca', 'modelo', 'color']),
        ])->values());
    }
}
