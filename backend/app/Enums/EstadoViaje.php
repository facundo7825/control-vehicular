<?php

namespace App\Enums;

enum EstadoViaje: string
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
}
