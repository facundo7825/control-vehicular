<?php

namespace App\Http\Controllers;

use App\Servicios\ServicioUbicacion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UbicacionController extends Controller
{
    public function __invoke(Request $request, ServicioUbicacion $ubicacion): Response
    {
        $datos = $request->validate([
            'puntos' => ['required', 'array', 'min:1', 'max:500'],
            'puntos.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'puntos.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'puntos.*.rumbo' => ['nullable', 'numeric', 'between:0,360'],
            'puntos.*.velocidad' => ['nullable', 'numeric', 'min:0'],
            'puntos.*.registrado_en' => ['required', 'date'],
        ]);

        $ubicacion->registrar($request->user(), $datos['puntos']);

        return response()->noContent();
    }
}
