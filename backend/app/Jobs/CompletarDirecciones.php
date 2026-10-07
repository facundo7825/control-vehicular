<?php

namespace App\Jobs;

use App\Events\ViajeActualizado;
use App\Models\Viaje;
use App\Servicios\CompletadorDirecciones;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reintenta la dirección de los puntos que quedaron sin ella al crear el viaje (3 intentos, cada vez más
 * espaciados para dejar pasar el corte de 60 s del geocodificador). Si consigue alguna, avisa con
 * `ViajeActualizado` (solo datos: sin push ni cierre de turno) para que la app y el panel la muestren.
 */
class CompletarDirecciones implements ShouldQueue
{
    use Queueable;

    /** Espera antes del primer intento; los siguientes, 60 s por intento hecho. */
    public const ESPERA_SEG = 30;

    public int $tries = 3;

    public function __construct(public int $viajeId) {}

    public function handle(CompletadorDirecciones $completador): void
    {
        $viaje = Viaje::find($this->viajeId);
        if ($viaje === null || ! $completador->faltan($viaje)) {
            return;
        }

        if ($completador->completarViaje($viaje)) {
            ViajeActualizado::dispatch($viaje, soloDatos: true);
        }

        if ($completador->faltan($viaje)) {
            if ($this->attempts() < $this->tries) {
                $this->release(60 * $this->attempts());
            } else {
                Log::info('No se pudo completar la dirección del viaje', ['viaje_id' => $viaje->id]);
            }
        }
    }
}
