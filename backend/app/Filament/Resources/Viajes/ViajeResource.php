<?php

namespace App\Filament\Resources\Viajes;

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Filament\Resources\Viajes\Pages\CreateViaje;
use App\Filament\Resources\Viajes\Pages\ListViajes;
use App\Filament\Resources\Viajes\Pages\ViewViaje;
use App\Filament\Resources\Viajes\RelationManagers\OfertasRelationManager;
use App\Mapas\BuscadorLugares;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\CalculadorEstadoChofer;
use App\Servicios\DisponibilidadReservas;
use App\Servicios\Parametros;
use App\Servicios\ServicioReservas;
use App\Servicios\ServicioViaje;
use BackedEnum;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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

    /**
     * "Nuevo viaje" (solo creación): el admin pide un viaje o una reserva para otra persona. Las mismas reglas
     * que la API (ViajeController::store y ReservaController::store); lo demás lo validan los servicios.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Pedido')
                    ->columns(2)
                    ->schema([
                        Select::make('solicitante_id')
                            ->label('Solicitante')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => self::solicitantes()
                                ->where('nombre', 'like', '%'.$search.'%')
                                ->orderBy('nombre')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Usuario $u) => [$u->id => self::etiquetaSolicitante($u)])
                                ->all())
                            // También valida: un usuario que no es solicitante activo no tiene etiqueta.
                            ->getOptionLabelUsing(fn ($value): ?string => ($u = self::solicitantes()->find($value))
                                ? self::etiquetaSolicitante($u)
                                : null)
                            ->helperText('El viaje queda a su nombre: si su cargo es prioritario, es obligatorio.')
                            ->required(),
                        ToggleButtons::make('tipo')
                            ->label('Tipo')
                            ->options(collect(TipoViaje::cases())->mapWithKeys(fn (TipoViaje $t) => [$t->value => $t->getLabel()])->all())
                            ->inline()
                            ->default(TipoViaje::Inmediato->value)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $set('modo', $state === TipoViaje::Reserva->value
                                    ? ModoViaje::CualquieraDisponible->value
                                    : ModoViaje::MasCercano->value);
                                $set('chofer_id', null);
                            }),
                        DateTimePicker::make('programado_para')
                            ->label('Programado para (hora local)')
                            // Sin zona: el valor es hora local, como la que manda la app (HoraLocal::interpretar).
                            ->seconds(false)
                            ->visible(fn (Get $get): bool => $get('tipo') === TipoViaje::Reserva->value)
                            ->required()
                            ->live(onBlur: true),
                        Select::make('modo')
                            ->label('Modo')
                            ->options(fn (Get $get): array => collect($get('tipo') === TipoViaje::Reserva->value
                                ? [ModoViaje::CualquieraDisponible, ModoViaje::Especifico]
                                : [ModoViaje::MasCercano, ModoViaje::Especifico])
                                ->mapWithKeys(fn (ModoViaje $m) => [$m->value => $m->getLabel()])
                                ->all())
                            ->default(ModoViaje::MasCercano->value)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live(),
                        Select::make('chofer_id')
                            ->label('Chofer')
                            ->options(fn (Get $get): array => self::choferesParaNuevoViaje($get))
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('modo') === ModoViaje::Especifico->value)
                            ->helperText(fn (Get $get): string => $get('tipo') === TipoViaje::Reserva->value
                                ? 'Choferes con la franja libre en su agenda (elegí antes la hora, el origen y el destino).'
                                : 'Choferes libres ahora.')
                            ->required(),
                        TextInput::make('motivo')
                            ->label('Motivo')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
                Section::make('Origen')->schema(self::camposLugar('origen')),
                Section::make('Destino')->schema(self::camposLugar('destino')),
            ]);
    }

    /**
     * Buscador de un punto (origen o destino). Sin autocompletar (política de Nominatim): se busca con el
     * botón y los resultados llenan un select; al elegir uno se fijan la dirección y las coordenadas, que
     * también se pueden cargar a mano en "Coordenadas".
     *
     * @return array<int, mixed>
     */
    private static function camposLugar(string $punto): array
    {
        return [
            TextInput::make("{$punto}_busqueda")
                ->label('Buscar dirección o lugar')
                ->maxLength(200)
                ->dehydrated(false)
                ->suffixAction(Action::make('buscar')
                    ->label('Buscar')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->action(fn (Get $get, Set $set) => self::buscarLugares($punto, $get, $set))),
            // Los resultados de la última búsqueda, como JSON: un texto viaja bien en un input oculto.
            Hidden::make("{$punto}_resultados")
                ->default('[]')
                ->dehydrated(false),
            Select::make("{$punto}_lugar")
                ->label('Resultados')
                ->placeholder('Buscá y elegí un resultado')
                ->options(fn (Get $get): array => array_column(self::resultados($get, $punto), 'direccion'))
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, $state) use ($punto): void {
                    $lugar = self::resultados($get, $punto)[$state] ?? null;
                    if (! isset($lugar['direccion'], $lugar['lat'], $lugar['lng'])) {
                        return;
                    }
                    $set("{$punto}_direccion", mb_substr($lugar['direccion'], 0, 255));
                    $set("{$punto}_lat", $lugar['lat']);
                    $set("{$punto}_lng", $lugar['lng']);
                }),
            Section::make('Coordenadas')
                ->description('Se completan al elegir un resultado; también se pueden cargar a mano.')
                ->collapsible()
                ->collapsed()
                ->columns(3)
                ->schema([
                    TextInput::make("{$punto}_direccion")->label('Dirección')->maxLength(255),
                    TextInput::make("{$punto}_lat")->label('Latitud')->numeric()->minValue(-90)->maxValue(90)->required()->live(onBlur: true),
                    TextInput::make("{$punto}_lng")->label('Longitud')->numeric()->minValue(-180)->maxValue(180)->required()->live(onBlur: true),
                ]),
        ];
    }

    private static function buscarLugares(string $punto, Get $get, Set $set): void
    {
        $texto = trim((string) $get("{$punto}_busqueda"));
        $set("{$punto}_lugar", null);
        $set("{$punto}_resultados", '[]');

        if (mb_strlen($texto) < 3) {
            Notification::make()->warning()->title('Escribí al menos 3 letras para buscar.')->send();

            return;
        }

        // El destino se busca cerca del origen, si ya está.
        $cerca = $punto === 'destino' && is_numeric($get('origen_lat')) && is_numeric($get('origen_lng'))
            ? [(float) $get('origen_lat'), (float) $get('origen_lng')]
            : [null, null];
        $resultados = app(BuscadorLugares::class)->buscar(mb_substr($texto, 0, 200), ...$cerca);

        if ($resultados === []) {
            Notification::make()->warning()->title('No se encontraron lugares.')->send();

            return;
        }

        $set("{$punto}_resultados", json_encode(array_values($resultados)));
    }

    /** @return list<array{nombre: string, direccion: string, lat: float, lng: float}> */
    private static function resultados(Get $get, string $punto): array
    {
        $resultados = json_decode((string) $get("{$punto}_resultados"), true);

        return is_array($resultados) ? $resultados : [];
    }

    /** Usuarios a nombre de los que se puede pedir un viaje. */
    private static function solicitantes(): Builder
    {
        return Usuario::query()->where('rol', RolUsuario::Solicitante)->where('activo', true);
    }

    private static function etiquetaSolicitante(Usuario $u): string
    {
        return $u->cargo ? "{$u->nombre} ({$u->cargo})" : $u->nombre;
    }

    /** @return array<int, string> */
    private static function choferesParaNuevoViaje(Get $get): array
    {
        if ($get('tipo') !== TipoViaje::Reserva->value) {
            return self::choferesLibres();
        }

        $franja = [];
        foreach (['programado_para', 'origen_lat', 'origen_lng', 'destino_lat', 'destino_lng'] as $campo) {
            $franja[$campo] = $get($campo);
            if (blank($franja[$campo]) || ($campo !== 'programado_para' && ! is_numeric($franja[$campo]))) {
                return [];
            }
        }

        // Las opciones se piden varias veces por request (render, validación, ayuda): la franja (que consulta la
        // duración de la ruta) y los choferes se calculan una vez por request y por datos de la franja.
        return once(function () use ($franja): array {
            try {
                [$inicio, $duracion] = app(ServicioReservas::class)->franja($franja);
            } catch (ReglaNegocio|InvalidFormatException) {
                // La hora no cumple la anticipación (o no se entiende): todavía no hay franja.
                return [];
            }

            return self::choferesConFranjaLibre($inicio, $duracion);
        });
    }

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
        // Dirección acortada (completa en el tooltip); sin dirección, las coordenadas.
        $direccion = fn (string $punto, string $etiqueta) => TextColumn::make("{$punto}_direccion")
            ->label($etiqueta)
            ->state(fn (Viaje $record): string => self::describirLugar($record, $punto))
            ->limit(30)
            ->tooltip(fn (Viaje $record): ?string => mb_strlen(self::describirLugar($record, $punto)) > 30 ? self::describirLugar($record, $punto) : null)
            ->searchable();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['solicitante', 'chofer']))
            ->columns([
                TextColumn::make('id')->label('#')->sortable()->searchable(),
                TextColumn::make('tipo')->badge(),
                TextColumn::make('estado')->badge(),
                IconColumn::make('obligatorio')->boolean(),
                TextColumn::make('solicitante.nombre')->label('Solicitante')->searchable(),
                TextColumn::make('chofer.nombre')->label('Chofer')->placeholder('—')->searchable(),
                $direccion('origen', 'Origen'),
                $direccion('destino', 'Destino'),
                TextColumn::make('programado_para')->label('Programado')->dateTime('d/m H:i', $zona)->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label('Pedido')->dateTime('d/m H:i', $zona)->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('tipo')->options(TipoViaje::class),
                SelectFilter::make('estado')->options(EstadoViaje::class)->multiple(),
                TernaryFilter::make('obligatorio'),
                SelectFilter::make('solicitante')
                    ->relationship('solicitante', 'nombre', fn (Builder $query) => $query->where('rol', RolUsuario::Solicitante))
                    ->searchable()
                    ->preload(),
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
                self::accionAsignar(),
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
        $elegibles = $viaje->tipo === TipoViaje::Reserva
            ? self::choferesConFranjaLibre(
                $viaje->programado_para,
                $viaje->duracion_estimada_min ?? app(Parametros::class)->entero('duracion_reserva_por_defecto_min'),
            )
            : self::choferesLibres();

        if ($viaje->chofer_id !== null) {
            unset($elegibles[$viaje->chofer_id]);
        }

        return $elegibles;
    }

    /** @return array<int, string> choferes activos libres ahora, con la patente de su turno */
    public static function choferesLibres(): array
    {
        return app(CalculadorEstadoChofer::class)->libres()
            ->filter(fn (Usuario $c) => $c->activo)
            ->mapWithKeys(fn (Usuario $c) => [$c->id => "{$c->nombre} ({$c->turnoAbierto?->vehiculo?->patente})"])
            ->all();
    }

    /** @return array<int, string> choferes con la franja libre en su agenda, los menos cargados ese día primero */
    public static function choferesConFranjaLibre(Carbon $inicio, int $duracionMin): array
    {
        return app(DisponibilidadReservas::class)
            ->choferesDisponibles($inicio, $duracionMin)
            ->mapWithKeys(fn (array $f) => [$f['chofer']->id => "{$f['chofer']->nombre} ({$f['reservas_del_dia']} reservas ese día)"])
            ->all();
    }

    /**
     * "Asignar chofer" para un viaje que todavía no lo tiene (buscando, ofrecido o sin chofer), en la lista y
     * en el detalle. Asignación directa, como "Reasignar", con los mismos choferes elegibles.
     */
    public static function accionAsignar(): Action
    {
        return Action::make('asignar')
            ->label('Asignar chofer')
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading('Asignar un chofer')
            ->modalDescription('Se asigna directo, sin oferta, y se avisa al chofer y al solicitante. Vencen las ofertas pendientes.')
            ->visible(fn (Viaje $record): bool => ServicioViaje::asignable($record))
            ->schema([
                Select::make('chofer_id')
                    ->label('Chofer')
                    ->options(fn (Viaje $record): array => self::choferesElegibles($record))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Viaje $record, array $data): void {
                try {
                    app(ServicioViaje::class)->asignarPorAdmin($record, Usuario::findOrFail($data['chofer_id']));
                } catch (ReglaNegocio|AccionNoPermitida $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Chofer asignado')->send();
            });
    }

    /** Dirección de un punto, o sus coordenadas si no tiene. */
    public static function describirLugar(Viaje $viaje, string $punto): string
    {
        return $viaje->{"{$punto}_direccion"} ?? "{$viaje->{"{$punto}_lat"}}, {$viaje->{"{$punto}_lng"}}";
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
            'create' => CreateViaje::route('/create'),
            'view' => ViewViaje::route('/{record}'),
        ];
    }
}
