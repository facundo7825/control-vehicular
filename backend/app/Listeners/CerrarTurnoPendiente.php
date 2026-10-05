<?php

namespace App\Listeners;

use App\Events\ViajeActualizado;
use App\Models\Turno;
use App\Models\Usuario;
use App\Servicios\ServicioAsistencia;

/**
 * El chofer que fichó la salida con un viaje activo quedó con cierre pendiente: cuando el viaje deja de
 * ocuparlo (finalizado, cancelado, reasignado) se le cierra el turno. ViajeActualizado se despacha después
 * del commit, así que el cierre (que bloquea la fila del chofer) no corre dentro de la transacción del viaje.
 */
class CerrarTurnoPendiente
{
    public function __construct(private ServicioAsistencia $asistencia) {}

    public function handle(ViajeActualizado $e): void
    {
        $choferes = array_unique(array_filter([$e->viaje->chofer_id, $e->choferAnteriorId]));

        foreach ($choferes as $choferId) {
            $pendiente = Turno::where('chofer_id', $choferId)->whereNull('fin')->whereNotNull('cierre_pendiente_en')->exists();

            if ($pendiente && $chofer = Usuario::find($choferId)) {
                $this->asistencia->cerrarPendiente($chofer);
            }
        }
    }
}
