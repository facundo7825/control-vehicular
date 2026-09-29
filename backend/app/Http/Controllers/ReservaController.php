<?php

namespace App\Http\Controllers;

use App\Http\Resources\ViajeResource;
use App\Servicios\ServicioReservas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservaController extends Controller
{
    private const REGLAS_FRANJA = [
        'programado_para' => ['required', 'date'],
        'origen_lat' => ['required', 'numeric', 'between:-90,90'],
        'origen_lng' => ['required', 'numeric', 'between:-180,180'],
        'destino_lat' => ['required', 'numeric', 'between:-90,90'],
        'destino_lng' => ['required', 'numeric', 'between:-180,180'],
    ];

    public function __construct(private ServicioReservas $reservas) {}

    public function disponibles(Request $request): JsonResponse
    {
        return response()->json($this->reservas->disponibles($request->validate(self::REGLAS_FRANJA)));
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            ...self::REGLAS_FRANJA,
            'modo' => ['required', 'in:especifico,cualquiera_disponible'],
            'chofer_id' => ['required_if:modo,especifico', 'nullable', 'integer'],
            'origen_direccion' => ['nullable', 'string', 'max:255'],
            'destino_direccion' => ['nullable', 'string', 'max:255'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        return (new ViajeResource($this->reservas->crear($request->user(), $datos)))
            ->response()
            ->setStatusCode(201);
    }
}
