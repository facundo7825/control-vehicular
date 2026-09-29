<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ResultadoOferta: string implements HasColor, HasLabel
{
    case Pendiente = 'pendiente';
    case Aceptada = 'aceptada';
    case Rechazada = 'rechazada';
    case Expirada = 'expirada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Aceptada => 'Aceptada',
            self::Rechazada => 'Rechazada',
            self::Expirada => 'Expirada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendiente => 'warning',
            self::Aceptada => 'success',
            self::Rechazada => 'danger',
            self::Expirada => 'gray',
        };
    }
}
