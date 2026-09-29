<?php

namespace App\Enums;

enum EstadoChofer: string
{
    case FueraDeTurno = 'fuera_de_turno';
    case SinSenal = 'sin_senal';
    case EnViaje = 'en_viaje';
    case ReservadoPronto = 'reservado_pronto';
    case Libre = 'libre';
}
