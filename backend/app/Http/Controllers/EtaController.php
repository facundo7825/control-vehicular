<?php

namespace App\Http\Controllers;

use App\Excepciones\AccionNoPermitida;
use App\Models\Viaje;
use App\Servicios\EstimadorLlegada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EtaController extends Controller
{
    public function __invoke(Request $request, Viaje $viaje, EstimadorLlegada $estimador): JsonResponse
    {
        $usuario = $request->user();

        if (! $usuario->esAdmin()
            && $viaje->solicitante_id !== $usuario->id
            && $viaje->chofer_id !== $usuario->id) {
            throw new AccionNoPermitida('Este viaje no es tuyo.');
        }

        return response()->json($estimador->estimar($viaje));
    }
}
