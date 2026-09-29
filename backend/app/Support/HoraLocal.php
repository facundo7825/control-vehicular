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
}
