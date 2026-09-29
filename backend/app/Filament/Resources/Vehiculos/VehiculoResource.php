<?php

namespace App\Filament\Resources\Vehiculos;

use App\Filament\Resources\Vehiculos\Pages\CreateVehiculo;
use App\Filament\Resources\Vehiculos\Pages\EditVehiculo;
use App\Filament\Resources\Vehiculos\Pages\ListVehiculos;
use App\Models\Vehiculo;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** ABM de vehículos (spec 8.2). No se borran los que tienen historial: se desactivan. */
class VehiculoResource extends Resource
{
    protected static ?string $model = Vehiculo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $modelLabel = 'vehículo';

    protected static ?string $pluralModelLabel = 'vehículos';

    protected static ?string $recordTitleAttribute = 'patente';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('patente')
                    ->required()
                    ->maxLength(20)
                    ->unique(),
                TextInput::make('marca')
                    ->required()
                    ->maxLength(100),
                TextInput::make('modelo')
                    ->required()
                    ->maxLength(100),
                TextInput::make('color')
                    ->maxLength(50),
                Toggle::make('activo')
                    ->helperText('Un vehículo inactivo no se ofrece para iniciar turno.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patente')->searchable()->sortable(),
                TextColumn::make('marca')->searchable(),
                TextColumn::make('modelo')->searchable(),
                TextColumn::make('color'),
                IconColumn::make('activo')->boolean(),
            ])
            ->defaultSort('patente')
            ->filters([
                TernaryFilter::make('activo'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehiculos::route('/'),
            'create' => CreateVehiculo::route('/create'),
            'edit' => EditVehiculo::route('/{record}/edit'),
        ];
    }
}
