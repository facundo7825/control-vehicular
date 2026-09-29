<?php

namespace App\Excepciones;

use Exception;
use Illuminate\Http\JsonResponse;

/** El usuario no tiene permitido realizar la acción (HTTP 403). */
class AccionNoPermitida extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 403);
    }
}
