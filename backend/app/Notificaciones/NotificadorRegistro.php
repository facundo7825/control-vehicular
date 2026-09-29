<?php

namespace App\Notificaciones;

use App\Models\Usuario;
use Illuminate\Support\Facades\Log;

/** Desarrollo: escribe los push en el log en lugar de enviarlos. */
class NotificadorRegistro implements Notificador
{
    public function enviar(Usuario $destino, string $titulo, string $cuerpo, array $datos = []): void
    {
        Log::info('Push (simulado)', ['destino' => $destino->id, 'titulo' => $titulo, 'cuerpo' => $cuerpo, 'datos' => $datos]);
    }
}
