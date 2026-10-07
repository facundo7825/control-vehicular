<?php

namespace App\Filament\Resources\Dependencias;

use App\Enums\RolUsuario;
use App\Filament\Resources\Dependencias\Pages\CreateDependencia;
use App\Filament\Resources\Dependencias\Pages\EditDependencia;
use App\Filament\Resources\Dependencias\Pages\ListDependencias;
use App\Models\Dependencia;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fueros u oficinas. Los viajes de sus personas se ofrecen primero a su chofer asignado y después a los
 * choferes que atienden su dependencia, antes que al resto.
 */
class DependenciaResource extends Resource
{
    protected static ?string $model = Dependencia::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'dependencia';

    protected static ?string $pluralModelLabel = 'dependencias';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?int $navigationSort = 35;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nombre')
                    ->required()
                    ->maxLength(255)
                    // Se valida el nombre como se va a guardar: "Fuero  Penal" choca con "Fuero Penal".
                    ->mutateStateForValidationUsing(fn (?string $state) => $state === null ? null : Dependencia::normalizar($state))
                    ->unique(),
                Toggle::make('activa')
                    ->helperText('Una dependencia inactiva no da prioridad a sus choferes ni se ofrece al cargar usuarios.')
                    ->default(true),
                Select::make('choferes')
                    ->label('Choferes que la atienden')
                    ->relationship(
                        'choferes',
                        'nombre',
                        // Choferes activos, más los que ya la atienden (para que no aparezcan como un id suelto).
                        modifyQueryUsing: fn (Builder $query, ?Dependencia $record) => $query
                            ->where('usuarios.rol', RolUsuario::Chofer)
                            ->where(fn (Builder $q) => $q->where('usuarios.activo', true)
                                ->when($record, fn (Builder $q) => $q->orWhereIn(
                                    'usuarios.id',
                                    $record->choferes()->select('usuarios.id'),
                                ))),
                    )
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->searchable()->sortable(),
                IconColumn::make('activa')->boolean(),
                TextColumn::make('choferes_count')->label('Choferes')->counts('choferes')->sortable(),
                TextColumn::make('personas_count')->label('Personas')->counts('personas')->sortable(),
            ])
            ->defaultSort('nombre')
            ->filters([
                TernaryFilter::make('activa'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDependencias::route('/'),
            'create' => CreateDependencia::route('/create'),
            'edit' => EditDependencia::route('/{record}/edit'),
        ];
    }
}
