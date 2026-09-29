<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RolUsuario: string implements HasLabel
{
    case Solicitante = 'solicitante';
    case Chofer = 'chofer';
    case Admin = 'admin';

    public function getLabel(): string
    {
        return match ($this) {
            self::Solicitante => 'Solicitante',
            self::Chofer => 'Chofer',
            self::Admin => 'Administrador',
        };
    }
}
