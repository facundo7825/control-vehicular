<?php

namespace App\Enums;

enum RolUsuario: string
{
    case Solicitante = 'solicitante';
    case Chofer = 'chofer';
    case Admin = 'admin';
}
