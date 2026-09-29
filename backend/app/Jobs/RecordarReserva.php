<?php

namespace App\Jobs;

use App\Models\Viaje;
use App\Notificaciones\Notificador;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Recordatorio de una reserva al solicitante y al chofer (spec 5.4 paso 5). */
class RecordarReserva implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $viajeId, public int $choferId, public int $programadoPara) {}

    public function handle(Notificador $push): void
    {
        $viaje = Viaje::with(['chofer', 'solicitante'])->find($this->viajeId);

        // Cancelada, sin chofer, reasignada, reprogramada o ya iniciada: este recordatorio no corresponde.
        if (! $viaje?->sigueReservadaPara($this->choferId, $this->programadoPara)) {
            return;
        }

        $cuando = $viaje->horaProgramadaLocal();
        $datos = ['tipo' => 'recordatorio_reserva', 'viaje_id' => $viaje->id];

        $push->enviar($viaje->solicitante, 'Recordatorio de reserva',
            "Tu viaje reservado es el $cuando con {$viaje->chofer->nombre}.", $datos);
        $push->enviar($viaje->chofer, 'Recordatorio de reserva',
            "Tenés una reserva el $cuando desde ".($viaje->origen_direccion ?? 'el punto marcado en el mapa').'.', $datos);
    }
}
