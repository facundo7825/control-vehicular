<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pedidos entre servidores del sistema de asistencia del PJ: encabezado X-Clave-Asistencia contra
 * ASISTENCIA_CLAVE. Sin clave configurada la integración está apagada (503).
 */
class AsegurarClaveAsistencia
{
    public function handle(Request $request, Closure $next): Response
    {
        $clave = (string) config('vehiculos.asistencia.clave');

        if ($clave === '') {
            return response()->json(['message' => 'La integración con asistencia no está habilitada.'], 503);
        }

        $recibida = $request->header('X-Clave-Asistencia');
        if (! is_string($recibida) || ! hash_equals($clave, $recibida)) {
            return response()->json(['message' => 'Clave de asistencia inválida.'], 401);
        }

        return $next($request);
    }
}
