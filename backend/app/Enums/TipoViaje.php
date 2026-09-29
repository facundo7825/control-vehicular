<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TipoViaje: string implements HasLabel
{
    case Inmediato = 'inmediato';
    case Reserva = 'reserva';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inmediato => 'Inmediato',
            self::Reserva => 'Reserva',
        };
    }
}
