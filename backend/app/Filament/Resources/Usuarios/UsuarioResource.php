<?php

namespace App\Filament\Resources\Usuarios;

use App\Enums\RolUsuario;
use App\Filament\Resources\Usuarios\Pages\EditUsuario;
use App\Filament\Resources\Usuarios\Pages\ListUsuarios;
use App\Filament\Resources\Usuarios\RelationManagers\TurnosRelationManager;
use App\Models\Dependencia;
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
use Illuminate\Validation\Rule;

/**
 * Usuarios y choferes (spec 8.3). Nombre, cargo e id externo vienen del PJ y no se editan acá;
 * el admin cambia el rol, si está activo, el vehículo habitual (choferes), la dependencia y el chofer asignado
 * (el resto) y las dependencias que atiende (choferes). Los usuarios se crean al entrar por la app.
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
        $esChofer = fn (Get $get): bool => in_array($get('rol'), [RolUsuario::Chofer, RolUsuario::Chofer->value], true);

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
                    ->helperText(fn (?Usuario $record): ?string => self::rolDelPj($record) ? 'Viene del sistema del PJ.' : null)
                    ->disabled(fn (?Usuario $record): bool => $esUnoMismo($record) || self::rolDelPj($record)),
                Select::make('vehiculo_habitual_id')
                    ->label('Vehículo habitual')
                    ->helperText('Con el que se abre el turno al fichar la entrada.')
                    ->options(fn (?Usuario $record): array => self::vehiculosHabituales($record))
                    ->searchable()
                    ->nullable()
                    ->visible($esChofer),
                Select::make('dependenciasQueAtiende')
                    ->label('Dependencias que atiende')
                    ->helperText('Los viajes de esas dependencias se le ofrecen antes que al resto de los choferes.')
                    ->relationship(
                        'dependenciasQueAtiende',
                        'nombre',
                        // Activas, más las que ya atiende aunque se hayan desactivado.
                        modifyQueryUsing: fn (Builder $query, ?Usuario $record) => $query
                            ->where(fn (Builder $q) => $q->where('dependencias.activa', true)
                                ->when($record, fn (Builder $q) => $q->orWhereIn(
                                    'dependencias.id',
                                    $record->dependenciasQueAtiende()->select('dependencias.id'),
                                ))),
                    )
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->visible($esChofer),
                Select::make('dependencia_id')
                    ->label('Dependencia')
                    ->helperText(Dependencia::vieneDelPj() ? 'Viene del sistema del PJ.' : null)
                    ->options(fn (?Usuario $record): array => self::dependencias($record))
                    ->disabled(Dependencia::vieneDelPj())
                    ->searchable()
                    ->nullable()
                    ->hidden($esChofer),
                Select::make('chofer_asignado_id')
                    ->label('Chofer asignado')
                    ->helperText('Recibe primero los viajes que pide esta persona.')
                    ->options(fn (?Usuario $record): array => self::choferes($record))
                    ->rules([Rule::exists('usuarios', 'id')->where('rol', RolUsuario::Chofer->value)])
                    ->searchable()
                    ->nullable()
                    ->hidden($esChofer),
                Toggle::make('activo')
                    ->helperText('Un usuario inactivo no puede entrar a la app ni al panel.')
                    ->disabled($esUnoMismo),
            ]);
    }

    /**
     * Con IDENTIDAD_CAMPO_ROL, el rol de las personas del PJ (chofer o solicitante) lo define el PJ al ingresar.
     * Los administradores del panel se siguen editando acá.
     */
    public static function rolDelPj(?Usuario $usuario): bool
    {
        return Usuario::rolVieneDelPj() && filled($usuario?->id_externo) && ! $usuario->esAdmin();
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

    /**
     * Dependencias activas, más la actual aunque se haya desactivado.
     *
     * @return array<int, string>
     */
    private static function dependencias(?Usuario $usuario): array
    {
        return Dependencia::query()
            ->where(fn (Builder $q) => $q->where('activa', true)->orWhere('id', $usuario?->dependencia_id))
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->all();
    }

    /**
     * Choferes activos, más el asignado actual aunque se haya desactivado.
     *
     * @return array<int, string>
     */
    private static function choferes(?Usuario $usuario): array
    {
        return Usuario::query()
            ->where('rol', RolUsuario::Chofer)
            ->where(fn (Builder $q) => $q->where('activo', true)->orWhere('id', $usuario?->chofer_asignado_id))
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
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
                TextColumn::make('dependencia.nombre')->label('Dependencia')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('choferAsignado.nombre')->label('Chofer asignado')->toggleable(isToggledHiddenByDefault: true),
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
