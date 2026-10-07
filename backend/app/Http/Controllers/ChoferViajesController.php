<?php

namespace App\Http\Controllers;

use App\Enums\EstadoViaje;
use App\Http\Resources\ViajeResource;
use App\Models\Viaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ChoferViajesController extends Controller
{
    private const LIMITE = 50;

    /** "Mis viajes" del chofer: resumen del día local y sus últimos viajes finalizados o cancelados. */
    public function __invoke(Request $request): JsonResponse
    {
        $chofer = $request->user();

        $inicioDia = Carbon::now(config('vehiculos.zona_horaria'))->startOfDay();
        $hoy = Viaje::where('chofer_id', $chofer->id)
            ->where('estado', EstadoViaje::Finalizado)
            ->where('finalizado_en', '>=', $inicioDia->copy()->utc())
            ->where('finalizado_en', '<', $inicioDia->copy()->addDay()->utc())
            ->selectRaw('COUNT(*) as viajes, COALESCE(SUM(metros_recorridos), 0) as metros')
            ->first();

        $viajes = Viaje::where('chofer_id', $chofer->id)
            ->whereIn('estado', [EstadoViaje::Finalizado, EstadoViaje::Cancelado])
            ->with(['chofer', 'vehiculo', 'solicitante'])
            ->orderByRaw('COALESCE(finalizado_en, cancelado_en) DESC')
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'hoy' => [
                'viajes' => (int) $hoy->viajes,
                'metros' => (int) $hoy->metros,
                'en_turno_desde' => $chofer->turnoAbierto()->first()?->inicio,
            ],
            'viajes' => ViajeResource::collection($viajes)->resolve($request),
        ]);
    }
}
