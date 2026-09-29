<?php

namespace App\Filament\Resources\Vehiculos\Pages;

use App\Filament\Resources\Vehiculos\VehiculoResource;
use App\Models\Vehiculo;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVehiculo extends EditRecord
{
    protected static string $resource = VehiculoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Con turnos o viajes lo referencian claves foráneas: se desactiva en lugar de borrarse.
            DeleteAction::make()->hidden(fn (Vehiculo $record): bool => $record->tieneHistorial()),
        ];
    }
}
