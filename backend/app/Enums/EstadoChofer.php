<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EstadoChofer: string implements HasColor, HasLabel
{
    case FueraDeTurno = 'fuera_de_turno';
    case SinSenal = 'sin_senal';
    case EnViaje = 'en_viaje';
    case ReservadoPronto = 'reservado_pronto';
    case Libre = 'libre';

    public function getLabel(): string
    {
        return match ($this) {
            self::FueraDeTurno => 'Fuera de turno',
            self::SinSenal => 'Sin señal',
            self::EnViaje => 'En viaje',
            self::ReservadoPronto => 'Reservado pronto',
            self::Libre => 'Libre',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FueraDeTurno => 'gray',
            self::SinSenal => 'danger',
            self::EnViaje => 'info',
            self::ReservadoPronto => 'warning',
            self::Libre => 'success',
        };
    }
}
