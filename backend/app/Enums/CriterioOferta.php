<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Por qué se le ofreció el viaje a ese chofer: el grupo en que estaba al ordenar los candidatos. */
enum CriterioOferta: string implements HasLabel
{
    case ChoferAsignado = 'chofer_asignado';
    case Dependencia = 'dependencia';
    case Cercania = 'cercania';

    public function getLabel(): string
    {
        return match ($this) {
            self::ChoferAsignado => 'Chofer asignado',
            self::Dependencia => 'Su dependencia',
            self::Cercania => 'Cercanía',
        };
    }
}
