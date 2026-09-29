<?php

namespace App\Http\Controllers;

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\TipoViaje;
use App\Http\Resources\ViajeResource;
use App\Models\OfertaViaje;
use App\Models\Viaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Agenda del chofer: reservas confirmadas y solicitudes de reserva por responder (spec 7, chofer 5). */
class AgendaController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $choferId = $request->user()->id;

        $reservas = Viaje::where('chofer_id', $choferId)
            ->where('tipo', TipoViaje::Reserva)
            ->where('estado', EstadoViaje::Aceptado)
            ->with(['chofer', 'vehiculo', 'solicitante'])
            ->orderBy('programado_para')
            ->get();

        $solicitudes = OfertaViaje::where('chofer_id', $choferId)
            ->where('resultado', ResultadoOferta::Pendiente)
            ->where('vence_en', '>', now())
            ->whereHas('viaje', fn ($q) => $q->where('tipo', TipoViaje::Reserva))
            ->with(['viaje.chofer', 'viaje.vehiculo', 'viaje.solicitante'])
            ->orderBy('vence_en')
            ->get();

        return response()->json([
            'reservas' => ViajeResource::collection($reservas),
            'solicitudes' => $solicitudes->map(fn (OfertaViaje $o) => [
                'id' => $o->id,
                'vence_en' => $o->vence_en->toIso8601String(),
                'viaje' => new ViajeResource($o->viaje),
            ])->values(),
        ]);
    }
}
