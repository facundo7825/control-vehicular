<?php

namespace App\Filament\Resources\CargosPrioritarios\Pages;

use App\Filament\Resources\CargosPrioritarios\CargoPrioritarioResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCargosPrioritarios extends ListRecords
{
    protected static string $resource = CargoPrioritarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
