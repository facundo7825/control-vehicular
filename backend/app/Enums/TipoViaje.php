<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TipoViaje: string implements HasLabel
{
    case Inmediato = 'inmediato';
    case Reserva = 'reserva';
    // Al interior o a otra provincia: lo carga el encargado y lo asigna directo, con chofer y vehículo.
    case Largo = 'largo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inmediato => 'Inmediato',
            self::Reserva => 'Reserva',
            self::Largo => 'Viaje largo',
        };
    }

    /**
     * Los que ocupan la agenda del chofer en una franja a futuro (programado_para + duracion_estimada_min):
     * reservas y viajes largos.
     *
     * @return array<TipoViaje>
     */
    public static function agendados(): array
    {
        return [self::Reserva, self::Largo];
    }

    public function esAgendado(): bool
    {
        return in_array($this, self::agendados(), true);
    }
}
