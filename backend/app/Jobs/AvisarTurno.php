<?php

namespace App\Jobs;

use App\Models\Usuario;
use App\Notificaciones\Notificador;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Push al chofer por un cambio de su turno que hizo un fichaje (ServicioAsistencia). Va por la cola y se
 * despacha después del commit: un lote de fichajes no espera a FCM y una transacción revertida no avisa.
 */
class AvisarTurno implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $choferId, public string $titulo, public string $cuerpo, public string $estado)
    {
        $this->afterCommit();
    }

    public function handle(Notificador $push): void
    {
        if ($chofer = Usuario::find($this->choferId)) {
            $push->enviar($chofer, $this->titulo, $this->cuerpo, ['tipo' => 'turno', 'estado' => $this->estado]);
        }
    }
}
