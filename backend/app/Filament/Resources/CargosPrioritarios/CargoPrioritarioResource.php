<?php

namespace App\Filament\Resources\CargosPrioritarios;

use App\Filament\Resources\CargosPrioritarios\Pages\CreateCargoPrioritario;
use App\Filament\Resources\CargosPrioritarios\Pages\EditCargoPrioritario;
use App\Filament\Resources\CargosPrioritarios\Pages\ListCargosPrioritarios;
use App\Models\CargoPrioritario;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Cargos que generan viajes obligatorios (spec 8.4). El cargo se compara tal cual llega del PJ. */
class CargoPrioritarioResource extends Resource
{
    protected static ?string $model = CargoPrioritario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $modelLabel = 'cargo prioritario';

    protected static ?string $pluralModelLabel = 'cargos prioritarios';

    protected static ?string $slug = 'cargos-prioritarios';

    protected static ?string $recordTitleAttribute = 'cargo';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('cargo')
                    ->required()
                    ->maxLength(255)
                    ->unique()
                    ->helperText('Tal como lo informa el Poder Judicial (se distinguen mayúsculas y acentos).'),
                Toggle::make('obligatorio')
                    ->helperText('Los viajes de este cargo se asignan directo y el chofer no puede rechazarlos.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cargo')->searchable()->sortable(),
                IconColumn::make('obligatorio')->boolean(),
            ])
            ->defaultSort('cargo')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCargosPrioritarios::route('/'),
            'create' => CreateCargoPrioritario::route('/create'),
            'edit' => EditCargoPrioritario::route('/{record}/edit'),
        ];
    }
}
