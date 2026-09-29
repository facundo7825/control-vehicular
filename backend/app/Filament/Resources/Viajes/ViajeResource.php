<?php

namespace App\Filament\Resources\Viajes;

use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Filament\Resources\Viajes\Pages\ListViajes;
use App\Filament\Resources\Viajes\Pages\ViewViaje;
use App\Filament\Resources\Viajes\RelationManagers\OfertasRelationManager;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\CalculadorEstadoChofer;
use App\Servicios\DisponibilidadReservas;
use App\Servicios\Parametros;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Viajes y reservas (spec 8.5): listado con filtros y detalle con línea de tiempo, ofertas y recorrido. */
class ViajeResource extends Resource
{
    protected static ?string $model = Viaje::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $modelLabel = 'viaje';

    protected static ?string $pluralModelLabel = 'viajes y reservas';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        $fecha = fn (string $campo, string $etiqueta) => TextEntry::make($campo)
            ->label($etiqueta)
            ->dateTime('d/m/Y H:i', config('vehiculos.zona_horaria'))
            ->placeholder('—');

        return $schema
            ->components([
                Section::make('Viaje')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('estado')->badge(),
                        TextEntry::make('tipo')->badge(),
                        TextEntry::make('modo'),
                        IconEntry::make('obligatorio')->boolean(),
                        TextEntry::make('solicitante.nombre')->label('Solicitante'),
                        TextEntry::make('solicitante.cargo')->label('Cargo')->placeholder('—'),
                        TextEntry::make('chofer.nombre')->label('Chofer')->placeholder('Sin asignar'),
                        TextEntry::make('vehiculo.patente')->label('Vehículo')->placeholder('—'),
                        TextEntry::make('motivo')->placeholder('—'),
                        TextEntry::make('origen_direccion')->label('Origen')
                            ->state(fn (Viaje $record): string => $record->origen_direccion ?? "{$record->origen_lat}, {$record->origen_lng}"),
                        TextEntry::make('destino_direccion')->label('Destino')
                            ->state(fn (Viaje $record): string => $record->destino_direccion ?? "{$record->destino_lat}, {$record->destino_lng}"),
                        TextEntry::make('duracion_estimada_min')->label('Duración estimada')->suffix(' min')->placeholder('—'),
                    ]),
                Section::make('Línea de tiempo')
                    ->columns(4)
                    ->schema([
                        $fecha('created_at', 'Pedido'),
                        $fecha('programado_para', 'Programado para'),
                        $fecha('aceptado_en', 'Aceptado'),
                        $fecha('llego_en', 'Llegó'),
                        $fecha('iniciado_en', 'Inició'),
                        $fecha('finalizado_en', 'Finalizó'),
                        $fecha('cancelado_en', 'Cancelado'),
                        TextEntry::make('cancelado_por')->label('Canceló')->placeholder('—'),
                        TextEntry::make('motivo_cancelacion')->label('Motivo de cancelación')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Recorrido')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('puntos_recorrido')->label('Puntos GPS')
                            ->state(fn (Viaje $record): int => $record->recorrido()->count()),
                        TextEntry::make('primer_punto')->label('Primer punto')->placeholder('—')
                            ->state(fn (Viaje $record): ?string => self::describirPunto($record, 'asc')),
                        TextEntry::make('ultimo_punto')->label('Último punto')->placeholder('—')
                            ->state(fn (Viaje $record): ?string => self::describirPunto($record, 'desc')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $zona = config('vehiculos.zona_horaria');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['solicitante', 'chofer']))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('tipo')->badge(),
                TextColumn::make('estado')->badge(),
                IconColumn::make('obligatorio')->boolean(),
                TextColumn::make('solicitante.nombre')->label('Solicitante')->searchable(),
                TextColumn::make('chofer.nombre')->label('Chofer')->placeholder('—')->searchable(),
                TextColumn::make('programado_para')->label('Programado')->dateTime('d/m H:i', $zona)->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label('Pedido')->dateTime('d/m H:i', $zona)->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('tipo')->options(TipoViaje::class),
                SelectFilter::make('estado')->options(EstadoViaje::class)->multiple(),
                TernaryFilter::make('obligatorio'),
                SelectFilter::make('chofer')
                    ->relationship('chofer', 'nombre', fn (Builder $query) => $query->where('rol', RolUsuario::Chofer))
                    ->searchable()
                    ->preload(),
                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde'),
                        DatePicker::make('hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::filtrarPorFecha($query, $data['desde'] ?? null, $data['hasta'] ?? null)),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Días en la zona de los usuarios. La fecha de un viaje es la programada (reservas) o la del pedido (inmediatos).
     */
    public static function filtrarPorFecha(Builder $query, ?string $desde, ?string $hasta): Builder
    {
        $zona = config('vehiculos.zona_horaria');
        $limite = fn (string $dia, bool $fin) => Carbon::parse($dia, $zona)
            ->{$fin ? 'endOfDay' : 'startOfDay'}()
            ->setTimezone(config('app.timezone'));

        foreach (array_filter(['>=' => $desde, '<=' => $hasta]) as $operador => $dia) {
            $momento = $limite($dia, $operador === '<=');
            $query->where(fn (Builder $q) => $q
                ->where('programado_para', $operador, $momento)
                ->orWhere(fn (Builder $i) => $i->whereNull('programado_para')->where('created_at', $operador, $momento)));
        }

        return $query;
    }

    /**
     * Choferes a los que el admin puede reasignar el viaje: libres ahora (inmediato) o con la franja libre (reserva).
     *
     * @return array<int, string>
     */
    public static function choferesElegibles(Viaje $viaje): array
    {
        if ($viaje->tipo === TipoViaje::Reserva) {
            return app(DisponibilidadReservas::class)
                ->choferesDisponibles(
                    $viaje->programado_para,
                    $viaje->duracion_estimada_min ?? app(Parametros::class)->entero('duracion_reserva_por_defecto_min'),
                )
                ->reject(fn (array $f) => $f['chofer']->id === $viaje->chofer_id)
                ->mapWithKeys(fn (array $f) => [$f['chofer']->id => "{$f['chofer']->nombre} ({$f['reservas_del_dia']} reservas ese día)"])
                ->all();
        }

        return app(CalculadorEstadoChofer::class)->libres()
            ->filter(fn (Usuario $c) => $c->activo && $c->id !== $viaje->chofer_id)
            ->mapWithKeys(fn (Usuario $c) => [$c->id => "{$c->nombre} ({$c->turnoAbierto?->vehiculo?->patente})"])
            ->all();
    }

    private static function describirPunto(Viaje $viaje, string $orden): ?string
    {
        $punto = $viaje->recorrido()->orderBy('registrado_en', $orden)->first();

        return $punto
            ? $punto->registrado_en->setTimezone(config('vehiculos.zona_horaria'))->format('H:i:s')." ({$punto->lat}, {$punto->lng})"
            : null;
    }

    public static function getRelations(): array
    {
        return [
            OfertasRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListViajes::route('/'),
            'view' => ViewViaje::route('/{record}'),
        ];
    }
}
