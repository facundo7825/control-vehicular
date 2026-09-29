<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AsegurarRol
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->rol?->value, $roles, true)) {
            return response()->json(['message' => 'No tenés permiso para esta acción.'], 403);
        }

        return $next($request);
    }
}
