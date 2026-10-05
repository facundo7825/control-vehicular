<?php

namespace App\Filament\Resources\Usuarios;

use App\Enums\RolUsuario;
use App\Filament\Resources\Usuarios\Pages\EditUsuario;
use App\Filament\Resources\Usuarios\Pages\ListUsuarios;
use App\Filament\Resources\Usuarios\RelationManagers\TurnosRelationManager;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Servicios\CalculadorEstadoChofer;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Usuarios y choferes (spec 8.3). Nombre, cargo e id externo vienen del PJ y no se editan acá;
 * el admin solo cambia el rol y si está activo. Los usuarios se crean al entrar por la app.
 */
class UsuarioResource extends Resource
{
    protected static ?string $model = Usuario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'usuarios y choferes';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        // Un admin no puede quitarse el rol ni desactivarse (EditUsuario lo refuerza al guardar).
        $esUnoMismo = fn (?Usuario $record): bool => $record?->is(Filament::auth()->user()) ?? false;

        return $schema
            ->components([
                TextInput::make('nombre')->disabled(),
                TextInput::make('cargo')->disabled(),
                TextInput::make('id_externo')->label('Id externo (PJ)')->disabled(),
                TextInput::make('email')->label('Email del panel')->disabled(),
                Select::make('rol')
                    ->options(RolUsuario::class)
                    ->required()
                    ->live()
                    ->disabled($esUnoMismo),
                Select::make('vehiculo_habitual_id')
                    ->label('Vehículo habitual')
                    ->helperText('Con el que se abre el turno al fichar la entrada.')
                    ->options(fn (?Usuario $record): array => self::vehiculosHabituales($record))
                    ->searchable()
                    ->nullable()
                    ->visible(fn (Get $get): bool => in_array($get('rol'), [RolUsuario::Chofer, RolUsuario::Chofer->value], true)),
                Toggle::make('activo')
                    ->helperText('Un usuario inactivo no puede entrar a la app ni al panel.')
                    ->disabled($esUnoMismo),
            ]);
    }

    /**
     * Vehículos activos como "PATENTE — Marca Modelo" (la búsqueda del select filtra por ese texto).
     * Incluye el habitual actual aunque se haya desactivado, para que no aparezca como un id suelto.
     *
     * @return array<int, string>
     */
    private static function vehiculosHabituales(?Usuario $usuario): array
    {
        return Vehiculo::query()
            ->where(fn (Builder $q) => $q->where('activo', true)->orWhere('id', $usuario?->vehiculo_habitual_id))
            ->orderBy('patente')
            ->get()
            ->mapWithKeys(fn (Vehiculo $v): array => [$v->id => "{$v->patente} — {$v->marca} {$v->modelo}"])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->searchable()->sortable(),
                TextColumn::make('cargo')->searchable(),
                TextColumn::make('rol')->badge(),
                TextColumn::make('estado_chofer')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (Usuario $record) => $record->esChofer()
                        ? app(CalculadorEstadoChofer::class)->estado($record)
                        : null),
                IconColumn::make('activo')->boolean(),
                TextColumn::make('id_externo')->label('Id externo')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre')
            ->filters([
                SelectFilter::make('rol')->options(RolUsuario::class),
                TernaryFilter::make('activo'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            TurnosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsuarios::route('/'),
            'edit' => EditUsuario::route('/{record}/edit'),
        ];
    }
}
