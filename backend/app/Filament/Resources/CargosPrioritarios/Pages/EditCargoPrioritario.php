<?php

namespace App\Filament\Resources\CargosPrioritarios\Pages;

use App\Filament\Resources\CargosPrioritarios\CargoPrioritarioResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCargoPrioritario extends EditRecord
{
    protected static string $resource = CargoPrioritarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
