<?php

namespace App\Http\Controllers;

use App\Excepciones\AccionNoPermitida;
use App\Http\Resources\ViajeResource;
use App\Models\OfertaViaje;
use App\Servicios\Despachador;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OfertaController extends Controller
{
    public function __construct(private Despachador $despachador) {}

    public function aceptar(Request $request, OfertaViaje $oferta): ViajeResource
    {
        $this->autorizar($request, $oferta);
        $this->despachador->responder($oferta, true);

        return new ViajeResource($oferta->viaje->fresh()->load(['chofer', 'vehiculo', 'solicitante']));
    }

    public function rechazar(Request $request, OfertaViaje $oferta): Response
    {
        $this->autorizar($request, $oferta);
        $this->despachador->responder($oferta, false);

        return response()->noContent();
    }

    private function autorizar(Request $request, OfertaViaje $oferta): void
    {
        if ($oferta->chofer_id !== $request->user()->id) {
            throw new AccionNoPermitida('Esta oferta no es tuya.');
        }
    }
}
