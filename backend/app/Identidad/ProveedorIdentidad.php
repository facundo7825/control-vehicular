<?php

namespace App\Identidad;

interface ProveedorIdentidad
{
    /**
     * Valida el token de sesión de la app del Poder Judicial.
     * Devuelve null si la sesión es inválida.
     *
     * @throws IdentidadNoDisponible si el servicio no responde.
     */
    public function validar(string $tokenExterno): ?DatosIdentidad;
}
