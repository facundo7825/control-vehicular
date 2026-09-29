<?php

namespace App\Servicios;

use App\Jobs\AlertarReservaSinTurno;
use App\Jobs\RecordarReserva;
use App\Models\Viaje;

/** Encola los recordatorios y la alerta de turno de una reserva recién aceptada (spec 5.4 pasos 5 y 6). */
class AvisosReserva
{
    public function __construct(private Parametros $parametros) {}

    public function programar(Viaje $viaje): void
    {
        $inicio = $viaje->programado_para;
        $marca = $inicio->getTimestamp();

        foreach (['recordatorio_reserva_1_min', 'recordatorio_reserva_2_min'] as $clave) {
            $momento = $inicio->copy()->subMinutes($this->parametros->entero($clave));
            if ($momento->isFuture()) {
                RecordarReserva::dispatch($viaje->id, $viaje->chofer_id, $marca)->delay($momento)->afterCommit();
            }
        }

        // Si la reserva se tomó con menos anticipación que la alerta, se verifica en el acto.
        if ($inicio->isFuture()) {
            AlertarReservaSinTurno::dispatch($viaje->id, $viaje->chofer_id, $marca)
                ->delay($inicio->copy()->subMinutes($this->parametros->entero('alerta_sin_turno_min')))
                ->afterCommit();
        }
    }
}
