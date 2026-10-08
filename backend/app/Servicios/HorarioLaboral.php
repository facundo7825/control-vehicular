<?php

namespace App\Servicios;

use App\Models\Viaje;
use Illuminate\Support\Carbon;

/**
 * Horario laboral (parámetros horario_laboral_inicio y horario_laboral_fin, horas enteras en la zona de los
 * usuarios). El panel marca "Fuera del horario laboral" un viaje terminado que empezó antes del inicio,
 * terminó después del fin o pasó de un día a otro.
 */
class HorarioLaboral
{
    public function __construct(private Parametros $parametros) {}

    public function fueraDeHorario(Viaje $viaje): bool
    {
        $inicio = $viaje->iniciado_en ?? $viaje->programado_para;
        $fin = $viaje->finalizado_en;
        if (! $inicio || ! $fin) {
            return false;
        }

        $zona = config('vehiculos.zona_horaria');
        $desde = Carbon::instance($inicio)->setTimezone($zona);
        $hasta = Carbon::instance($fin)->setTimezone($zona);

        $abre = $desde->copy()->startOfDay()->addHours($this->parametros->entero('horario_laboral_inicio'));
        $cierra = $desde->copy()->startOfDay()->addHours($this->parametros->entero('horario_laboral_fin'));

        return $desde->lt($abre) || $hasta->gt($cierra);
    }

    /** Minutos entre el inicio real (o la salida programada) y el fin; null si no terminó. */
    public function duracionRealMin(Viaje $viaje): ?int
    {
        $inicio = $viaje->iniciado_en ?? $viaje->programado_para;
        $fin = $viaje->finalizado_en;

        return $inicio && $fin ? (int) $inicio->diffInMinutes($fin) : null;
    }
}
