<?php

namespace App\Http\Controllers;

use App\Identidad\IdentidadNoDisponible;
use App\Identidad\ProveedorIdentidad;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function intercambio(Request $request, ProveedorIdentidad $identidad): JsonResponse
    {
        $datos = $request->validate(['token_externo' => ['required', 'string']]);

        try {
            $id = $identidad->validar($datos['token_externo']);
        } catch (IdentidadNoDisponible) {
            return response()->json(['message' => 'Servicio de identidad no disponible.'], 503);
        }

        if ($id === null) {
            return response()->json(['message' => 'Sesión inválida.'], 401);
        }

        $usuario = Usuario::updateOrCreate(
            ['id_externo' => $id->idExterno],
            array_filter(['nombre' => $id->nombre, 'cargo' => $id->cargo, 'telefono' => $id->telefono],
                fn ($v) => $v !== null),
        )->refresh();

        if (! $usuario->activo) {
            return response()->json(['message' => 'Usuario deshabilitado.'], 403);
        }

        $token = $usuario->createToken('app')->plainTextToken;
        $usuario->recortarTokens();

        return response()->json([
            'token' => $token,
            'usuario' => self::datosUsuario($usuario),
        ]);
    }

    public function yo(Request $request): JsonResponse
    {
        return response()->json(self::datosUsuario($request->user()));
    }

    public static function datosUsuario(Usuario $u): array
    {
        return ['id' => $u->id, 'nombre' => $u->nombre, 'cargo' => $u->cargo, 'rol' => $u->rol->value];
    }
}
