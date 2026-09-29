<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ModoViaje: string implements HasLabel
{
    case MasCercano = 'mas_cercano';
    case Especifico = 'especifico';
    case CualquieraDisponible = 'cualquiera_disponible';

    public function getLabel(): string
    {
        return match ($this) {
            self::MasCercano => 'Más cercano',
            self::Especifico => 'Chofer específico',
            self::CualquieraDisponible => 'Cualquiera disponible',
        };
    }
}
