<?php

namespace App\Enums;

enum ModoViaje: string
{
    case MasCercano = 'mas_cercano';
    case Especifico = 'especifico';
    case CualquieraDisponible = 'cualquiera_disponible';
}
