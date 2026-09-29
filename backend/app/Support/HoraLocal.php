<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Formatea momentos guardados en UTC en la zona horaria de los usuarios (textos de push y mensajes). */
final class HoraLocal
{
    public static function formatear(DateTimeInterface $momento, string $formato = 'd/m H:i'): string
    {
        return Carbon::instance($momento)->setTimezone(config('vehiculos.zona_horaria'))->format($formato);
    }

    /**
     * Interpreta una fecha recibida de la app y la devuelve en la zona de la app (UTC).
     * Si trae offset se respeta; si no lo trae, es hora local de los usuarios, no UTC.
     */
    public static function interpretar(string $valor): Carbon
    {
        return Carbon::parse($valor, config('vehiculos.zona_horaria'))->setTimezone(config('app.timezone'));
    }
}
