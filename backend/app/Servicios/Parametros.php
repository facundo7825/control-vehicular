<?php

namespace App\Servicios;

use App\Models\Parametro;
use InvalidArgumentException;

class Parametros
{
    public function entero(string $clave): int
    {
        $valor = Parametro::find($clave)?->valor ?? config("vehiculos.parametros.$clave");

        if ($valor === null) {
            throw new InvalidArgumentException("Parámetro desconocido: $clave");
        }

        return (int) $valor;
    }
}
