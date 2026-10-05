<?php

namespace App\Http\Controllers;

use App\Servicios\ServicioTurnos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TurnoController extends Controller
{
    public function __construct(private ServicioTurnos $turnos) {}

    public function vehiculosDisponibles(): JsonResponse
    {
        return response()->json($this->turnos->vehiculosDisponibles());
    }

    public function actual(Request $request): JsonResponse
    {
        return response()->json(['turno' => $request->user()->turnoAbierto()->with('vehiculo')->first()]);
    }

    public function iniciar(Request $request): JsonResponse
    {
        $datos = $request->validate(['vehiculo_id' => ['required', 'integer']]);

        $turno = $this->turnos->iniciar($request->user(), $datos['vehiculo_id']);

        return response()->json($turno->load('vehiculo'), 201);
    }

    /** Responde con la misma forma que actual(): {"turno": {..., "vehiculo": {...}}}. */
    public function cambiarVehiculo(Request $request): JsonResponse
    {
        $datos = $request->validate(['vehiculo_id' => ['required', 'integer']]);

        $turno = $this->turnos->cambiarVehiculo($request->user(), $datos['vehiculo_id']);

        return response()->json(['turno' => $turno->fresh('vehiculo')]);
    }

    public function finalizar(Request $request): JsonResponse
    {
        return response()->json($this->turnos->finalizar($request->user()));
    }
}
