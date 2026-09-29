<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Un usuario desactivado desde el panel no puede seguir usando la app aunque conserve un token. */
class AsegurarActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->activo) {
            return response()->json(['message' => 'Usuario deshabilitado.'], 403);
        }

        return $next($request);
    }
}
