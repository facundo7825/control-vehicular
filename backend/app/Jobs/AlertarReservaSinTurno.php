<?php

namespace App\Jobs;

use App\Models\Alerta;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Si el chofer no inició turno poco antes de la reserva, lo avisa a él y al panel (spec 5.4 paso 6). */
class AlertarReservaSinTurno implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $viajeId, public int $choferId, public int $programadoPara) {}

    public function handle(Notificador $push): void
    {
        $viaje = Viaje::with('chofer')->find($this->viajeId);

        if (! $viaje?->sigueReservadaPara($this->choferId, $this->programadoPara)
            || $viaje->chofer->turnoAbierto()->exists()) {
            return;
        }

        $cuando = $viaje->horaProgramadaLocal();

        Alerta::create([
            'tipo' => Alerta::RESERVA_SIN_TURNO,
            'viaje_id' => $viaje->id,
            'chofer_id' => $viaje->chofer_id,
            'mensaje' => "{$viaje->chofer->nombre} no inició turno y tiene una reserva el $cuando.",
        ]);

        $push->enviar($viaje->chofer, 'Iniciá tu turno',
            "Tenés una reserva el $cuando y todavía no iniciaste turno.",
            ['tipo' => 'alerta_reserva', 'viaje_id' => $viaje->id]);
    }
}
