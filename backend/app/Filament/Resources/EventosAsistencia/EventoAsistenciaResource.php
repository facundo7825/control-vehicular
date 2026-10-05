<?php

namespace App\Filament\Resources\EventosAsistencia;

use App\Filament\Resources\EventosAsistencia\Pages\ListEventosAsistencia;
use App\Filament\Resources\Usuarios\UsuarioResource;
use App\Models\EventoAsistencia;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Fichajes recibidos del control de asistencia y qué se hizo con cada uno (plan 2026-10-05). Solo lectura. */
class EventoAsistenciaResource extends Resource
{
    protected static ?string $model = EventoAsistencia::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?string $modelLabel = 'fichaje';

    protected static ?string $pluralModelLabel = 'fichajes';

    protected static ?int $navigationSort = 16;

    public const TIPOS = [
        EventoAsistencia::ENTRADA => 'Entrada',
        EventoAsistencia::SALIDA => 'Salida',
    ];

    /** Etiqueta de cada resultado; también la usa la acción "Simular fichaje" de Usuarios. */
    public const RESULTADOS = [
        EventoAsistencia::ABIERTO => 'Turno abierto',
        EventoAsistencia::CERRADO => 'Turno cerrado',
        EventoAsistencia::CIERRE_PENDIENTE => 'Cierre pendiente',
        EventoAsistencia::SIN_VEHICULO => 'Sin vehículo',
        EventoAsistencia::IGNORADO => 'Ignorado',
    ];

    public static function colorResultado(string $resultado): string
    {
        return match ($resultado) {
            EventoAsistencia::ABIERTO, EventoAsistencia::CERRADO => 'success',
            EventoAsistencia::CIERRE_PENDIENTE, EventoAsistencia::SIN_VEHICULO => 'warning',
            default => 'gray',
        };
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('usuario'))
            ->columns([
                TextColumn::make('momento')->label('Fecha')->dateTime('d/m H:i', config('vehiculos.zona_horaria'))->sortable(),
                TextColumn::make('persona')
                    ->state(fn (EventoAsistencia $record): string => $record->usuario?->nombre ?? "{$record->id_externo} (sin usuario)")
                    ->url(fn (EventoAsistencia $record): ?string => $record->usuario_id
                        ? UsuarioResource::getUrl('edit', ['record' => $record->usuario_id])
                        : null),
                TextColumn::make('tipo')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => self::TIPOS[$state] ?? $state),
                TextColumn::make('resultado')->badge()
                    ->formatStateUsing(fn (string $state): string => self::RESULTADOS[$state] ?? $state)
                    ->color(fn (string $state): string => self::colorResultado($state)),
                TextColumn::make('motivo')->wrap(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('momento')->orderByDesc('id'))
            ->filters([
                SelectFilter::make('resultado')->options(self::RESULTADOS),
                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde'),
                        DatePicker::make('hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::filtrarPorFecha($query, $data['desde'] ?? null, $data['hasta'] ?? null)),
            ]);
    }

    /** Días en la zona de los usuarios; los momentos se guardan en la zona de la app. */
    private static function filtrarPorFecha(Builder $query, ?string $desde, ?string $hasta): Builder
    {
        $zona = config('vehiculos.zona_horaria');

        if ($desde) {
            $query->where('momento', '>=', Carbon::parse($desde, $zona)->startOfDay()->setTimezone(config('app.timezone')));
        }
        if ($hasta) {
            $query->where('momento', '<=', Carbon::parse($hasta, $zona)->endOfDay()->setTimezone(config('app.timezone')));
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEventosAsistencia::route('/'),
        ];
    }
}
