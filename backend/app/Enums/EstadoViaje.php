<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EstadoViaje: string implements HasColor, HasLabel
{
    case Buscando = 'buscando';
    case Ofrecido = 'ofrecido';
    case Aceptado = 'aceptado';
    case EnCamino = 'en_camino';
    case Llego = 'llego';
    case EnCurso = 'en_curso';
    case Finalizado = 'finalizado';
    case Cancelado = 'cancelado';
    case SinChofer = 'sin_chofer';

    /** @return array<EstadoViaje> */
    public static function enProgreso(): array
    {
        return [self::Buscando, self::Ofrecido, ...self::conChofer()];
    }

    /** @return array<EstadoViaje> */
    public static function conChofer(): array
    {
        return [self::Aceptado, self::EnCamino, self::Llego, self::EnCurso];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Buscando => 'Buscando',
            self::Ofrecido => 'Ofrecido',
            self::Aceptado => 'Aceptado',
            self::EnCamino => 'En camino',
            self::Llego => 'Llegó',
            self::EnCurso => 'En curso',
            self::Finalizado => 'Finalizado',
            self::Cancelado => 'Cancelado',
            self::SinChofer => 'Sin chofer',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Buscando, self::Ofrecido => 'warning',
            self::Aceptado, self::EnCamino, self::Llego, self::EnCurso => 'info',
            self::Finalizado => 'success',
            self::Cancelado => 'gray',
            self::SinChofer => 'danger',
        };
    }
}
