<?php

namespace App\Enums;

enum ResultadoOferta: string
{
    case Pendiente = 'pendiente';
    case Aceptada = 'aceptada';
    case Rechazada = 'rechazada';
    case Expirada = 'expirada';
}
