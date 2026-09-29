<?php

namespace App\Filament\Resources\Viajes\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Ofertas del viaje (a quién se ofreció y qué respondió), solo lectura. */
class OfertasRelationManager extends RelationManager
{
    protected static string $relationship = 'ofertas';

    protected static ?string $title = 'Ofertas';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $zona = config('vehiculos.zona_horaria');

        return $table
            ->columns([
                TextColumn::make('chofer.nombre')->label('Chofer'),
                TextColumn::make('resultado')->badge(),
                TextColumn::make('ofrecido_en')->label('Ofrecida')->dateTime('d/m H:i:s', $zona),
                TextColumn::make('vence_en')->label('Vence')->dateTime('d/m H:i:s', $zona),
                TextColumn::make('respondido_en')->label('Respondida')->dateTime('d/m H:i:s', $zona)->placeholder('—'),
                TextColumn::make('motivo')->placeholder('—'),
            ])
            ->defaultSort('ofrecido_en');
    }
}
