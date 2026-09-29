<?php

namespace App\Identidad;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class EndpointPoderJudicial implements ProveedorIdentidad
{
    public function validar(string $tokenExterno): ?DatosIdentidad
    {
        try {
            $respuesta = Http::withToken($tokenExterno)
                ->acceptJson()
                ->timeout(config('vehiculos.identidad.timeout'))
                ->get(config('vehiculos.identidad.url'));
        } catch (ConnectionException $e) {
            throw new IdentidadNoDisponible('No se pudo contactar al servicio de identidad.', previous: $e);
        }

        if (in_array($respuesta->status(), [401, 403], true)) {
            return null;
        }

        if ($respuesta->failed()) {
            throw new IdentidadNoDisponible("El servicio de identidad respondió {$respuesta->status()}.");
        }

        $json = $respuesta->json();
        $campos = config('vehiculos.identidad.campos');
        $id = data_get($json, $campos['id_externo']);

        if ($id === null || $id === '') {
            return null;
        }

        return new DatosIdentidad(
            (string) $id,
            (string) data_get($json, $campos['nombre'], ''),
            data_get($json, $campos['cargo']),
            data_get($json, $campos['telefono']),
        );
    }
}
