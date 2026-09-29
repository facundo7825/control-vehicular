<?php

namespace App\Notificaciones;

use App\Models\Usuario;

interface Notificador
{
    /** Envía un push. Nunca lanza: los fallos se registran en el log. */
    public function enviar(Usuario $destino, string $titulo, string $cuerpo, array $datos = []): void;
}
