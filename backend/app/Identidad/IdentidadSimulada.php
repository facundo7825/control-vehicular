<?php

namespace App\Identidad;

/** Solo para desarrollo y tests. Token: "sim|<id_externo>|<nombre>|<cargo>". */
class IdentidadSimulada implements ProveedorIdentidad
{
    public function validar(string $tokenExterno): ?DatosIdentidad
    {
        $partes = explode('|', $tokenExterno);

        if (count($partes) !== 4 || $partes[0] !== 'sim' || $partes[1] === '') {
            return null;
        }

        return new DatosIdentidad($partes[1], $partes[2], $partes[3] !== '' ? $partes[3] : null);
    }
}
