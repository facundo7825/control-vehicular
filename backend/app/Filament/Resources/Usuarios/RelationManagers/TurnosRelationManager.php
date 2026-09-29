<?php

namespace App\Filament\Resources\Usuarios\RelationManagers;

use App\Models\Usuario;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Turnos del chofer, solo lectura: los abre y cierra el chofer desde la app. */
class TurnosRelationManager extends RelationManager
{
    protected static string $relationship = 'turnos';

    protected static ?string $title = 'Turnos';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Usuario && $ownerRecord->esChofer();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('inicio')->dateTime('d/m/Y H:i', config('vehiculos.zona_horaria'))->sortable(),
                TextColumn::make('fin')
                    ->dateTime('d/m/Y H:i', config('vehiculos.zona_horaria'))
                    ->placeholder('Abierto'),
                TextColumn::make('vehiculo.patente')->label('Vehículo'),
                TextColumn::make('origen')->formatStateUsing(fn ($state): string => ucfirst($state->value)),
            ])
            ->defaultSort('inicio', 'desc');
    }
}
