<?php

namespace App\Http\Controllers;

use App\Enums\RolUsuario;
use App\Identidad\IdentidadNoDisponible;
use App\Identidad\ProveedorIdentidad;
use App\Models\Dependencia;
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

        // Si el PJ no informa la dependencia, queda la que cargó el encargado en el panel.
        $dependenciaId = $id->dependencia !== null ? Dependencia::buscarOCrear($id->dependencia)?->id : null;

        $usuario = Usuario::updateOrCreate(
            ['id_externo' => $id->idExterno],
            array_filter([
                'nombre' => $id->nombre, 'cargo' => $id->cargo, 'telefono' => $id->telefono,
                'dependencia_id' => $dependenciaId,
            ], fn ($v) => $v !== null),
        )->refresh();

        self::aplicarRolDelPj($usuario, $id->esChofer);

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

    /**
     * Si el PJ informa el rol, define si la persona es chofer o solicitante. Un administrador del panel no se
     * toca, y a un chofer con el turno abierto o viajes asignados se le cambia recién en un ingreso posterior
     * (como en el panel: primero se cierra su turno y se reasignan sus viajes).
     */
    private static function aplicarRolDelPj(Usuario $usuario, ?bool $esChofer): void
    {
        if ($esChofer === null || $usuario->esAdmin()) {
            return;
        }

        $rol = $esChofer ? RolUsuario::Chofer : RolUsuario::Solicitante;
        if ($usuario->rol === $rol) {
            return;
        }
        if ($usuario->esChofer() && $usuario->tieneTrabajoDeChofer()) {
            return;
        }

        $usuario->update(['rol' => $rol]);
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
