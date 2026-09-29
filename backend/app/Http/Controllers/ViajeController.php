<?php

namespace App\Http\Controllers;

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\TipoViaje;
use App\Http\Resources\ViajeResource;
use App\Models\OfertaViaje;
use App\Models\Viaje;
use App\Servicios\ServicioViaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViajeController extends Controller
{
    private const RELACIONES = ['chofer', 'vehiculo', 'solicitante'];

    public function __construct(private ServicioViaje $viajes) {}

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'modo' => ['required', 'in:mas_cercano,especifico'],
            'chofer_id' => ['required_if:modo,especifico', 'nullable', 'integer'],
            'origen_lat' => ['required', 'numeric', 'between:-90,90'],
            'origen_lng' => ['required', 'numeric', 'between:-180,180'],
            'origen_direccion' => ['nullable', 'string', 'max:255'],
            'destino_lat' => ['required', 'numeric', 'between:-90,90'],
            'destino_lng' => ['required', 'numeric', 'between:-180,180'],
            'destino_direccion' => ['nullable', 'string', 'max:255'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        return (new ViajeResource($this->viajes->pedir($request->user(), $datos)))
            ->response()
            ->setStatusCode(201);
    }

    public function actual(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $oferta = null;

        if ($usuario->esChofer()) {
            $viaje = Viaje::activosDeChofer($usuario->id)->first();
            $oferta = OfertaViaje::where('chofer_id', $usuario->id)
                ->where('resultado', ResultadoOferta::Pendiente)
                ->where('vence_en', '>', now())
                ->first();
        } else {
            $viaje = Viaje::where('solicitante_id', $usuario->id)
                ->where('tipo', TipoViaje::Inmediato)
                ->whereIn('estado', EstadoViaje::enProgreso())
                ->latest('id')
                ->first();
        }

        return response()->json([
            'viaje' => $viaje ? new ViajeResource($viaje->load(self::RELACIONES)) : null,
            'oferta' => $oferta ? [
                'id' => $oferta->id,
                'vence_en' => $oferta->vence_en->toIso8601String(),
                'viaje' => new ViajeResource($oferta->viaje->load(self::RELACIONES)),
            ] : null,
        ]);
    }
}
