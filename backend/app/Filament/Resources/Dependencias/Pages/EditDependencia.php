<?php

namespace App\Filament\Resources\Dependencias\Pages;

use App\Filament\Resources\Dependencias\DependenciaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDependencia extends EditRecord
{
    protected static string $resource = DependenciaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
