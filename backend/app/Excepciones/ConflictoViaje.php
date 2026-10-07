<?php

namespace App\Excepciones;

use Illuminate\Http\JsonResponse;

/** La acción llegó tarde: el viaje cambió mientras tanto (cancelado o reasignado) (HTTP 409). */
class ConflictoViaje extends ReglaNegocio
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
