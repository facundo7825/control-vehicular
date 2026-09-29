<?php

namespace App\Excepciones;

use Exception;
use Illuminate\Http\JsonResponse;

/** Operación válida en forma pero no permitida por el estado actual (HTTP 422). */
class ReglaNegocio extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
