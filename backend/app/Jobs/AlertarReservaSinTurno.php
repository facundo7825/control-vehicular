<?php

namespace App\Jobs;

use App\Enums\TipoViaje;
use App\Models\Alerta;
use App\Models\Turno;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Si el chofer no inició turno poco antes de la reserva, lo avisa a él y al panel (spec 5.4 paso 6). En un viaje
 * largo, además, avisa al panel si su vehículo está en el turno de otro chofer (el chofer no podría salir con él).
 */
class AlertarReservaSinTurno implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $viajeId, public int $choferId, public int $programadoPara) {}

    public function handle(Notificador $push): void
    {
        $viaje = Viaje::with(['chofer', 'vehiculo'])->find($this->viajeId);

        if (! $viaje?->sigueReservadaPara($this->choferId, $this->programadoPara)) {
            return;
        }

        $cuando = $viaje->horaProgramadaLocal();

        if ($viaje->tipo === TipoViaje::Largo) {
            $this->alertarVehiculoEnUso($viaje, $cuando);
        }

        if ($viaje->chofer->turnoAbierto()->exists()) {
            return;
        }

        $cual = $viaje->tipo === TipoViaje::Largo ? 'un viaje largo' : 'una reserva';

        Alerta::create([
            'tipo' => Alerta::RESERVA_SIN_TURNO,
            'viaje_id' => $viaje->id,
            'chofer_id' => $viaje->chofer_id,
            'mensaje' => "{$viaje->chofer->nombre} no inició turno y tiene $cual el $cuando.",
        ]);

        $push->enviar($viaje->chofer, 'Iniciá tu turno',
            "Tenés $cual el $cuando y todavía no iniciaste turno.",
            ['tipo' => 'alerta_reserva', 'viaje_id' => $viaje->id]);
    }

    private function alertarVehiculoEnUso(Viaje $viaje, string $cuando): void
    {
        $otro = Turno::with('chofer')
            ->where('vehiculo_id', $viaje->vehiculo_id)
            ->whereNull('fin')
            ->where('chofer_id', '!=', $viaje->chofer_id)
            ->first();

        if (! $otro) {
            return;
        }

        Alerta::create([
            'tipo' => Alerta::VEHICULO_VIAJE_LARGO_EN_USO,
            'viaje_id' => $viaje->id,
            'chofer_id' => $viaje->chofer_id,
            'mensaje' => "El vehículo {$viaje->vehiculo->patente} del viaje largo de {$viaje->chofer->nombre} ($cuando) "
                ."está en el turno de {$otro->chofer->nombre}.",
        ]);
    }
}
