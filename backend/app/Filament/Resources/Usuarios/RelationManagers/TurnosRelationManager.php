<?php

namespace App\Filament\Resources\Usuarios\RelationManagers;

use App\Enums\OrigenTurno;
use App\Models\Turno;
use App\Models\Usuario;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/** Turnos del chofer, solo lectura: los abre y cierra el chofer desde la app o los fichajes de asistencia. */
class TurnosRelationManager extends RelationManager
{
    protected static string $relationship = 'turnos';

    protected static ?string $title = 'Turnos';

    /** Lo emite "Simular fichaje" (EditUsuario) para que la tabla muestre el turno abierto o cerrado. */
    public const EVENTO_ACTUALIZAR = 'turnos-actualizados';

    #[On(self::EVENTO_ACTUALIZAR)]
    public function actualizar(): void {}

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
                TextColumn::make('origen')->badge()
                    ->formatStateUsing(fn (OrigenTurno $state): string => match ($state) {
                        OrigenTurno::Manual => 'Manual',
                        OrigenTurno::Asistencia => 'Asistencia',
                    })
                    ->color(fn (OrigenTurno $state): string => $state === OrigenTurno::Asistencia ? 'info' : 'gray'),
                // Fichó la salida con un viaje activo: el turno se cierra solo cuando termine ese viaje.
                TextColumn::make('cierre_pendiente_en')->label('Cierre')->badge()->color('warning')
                    ->formatStateUsing(fn ($state): ?string => filled($state) ? 'Cierre pendiente' : null)
                    ->tooltip(fn (Turno $record): ?string => $record->cierre_pendiente_en
                        ? 'Fichó la salida el '.$record->cierre_pendiente_en->timezone(config('vehiculos.zona_horaria'))->format('d/m H:i').'; se cierra al terminar el viaje.'
                        : null),
            ])
            ->defaultSort('inicio', 'desc');
    }
}
