<?php

namespace App\Filament\Resources\Alertas;

use App\Filament\Resources\Alertas\Pages\ListAlertas;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\Alerta;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Alertas del panel (spec 8.6): reservas sin turno, viajes sin chofer y choferes sin señal durante un viaje. */
class AlertaResource extends Resource
{
    protected static ?string $model = Alerta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $modelLabel = 'alerta';

    protected static ?string $pluralModelLabel = 'alertas';

    protected static ?int $navigationSort = 15;

    /** Etiqueta de cada tipo de alerta; también la usa el aviso en vivo (AvisoAlertas). */
    public const TIPOS = [
        Alerta::RESERVA_SIN_TURNO => 'Reserva sin turno',
        Alerta::CHOFER_SIN_SENAL => 'Chofer sin señal',
        Alerta::VIAJE_SIN_CHOFER => 'Viaje sin chofer',
        Alerta::ASISTENCIA_SIN_VEHICULO => 'Fichaje sin vehículo',
        Alerta::VEHICULO_VIAJE_LARGO_EN_USO => 'Vehículo de viaje largo en uso',
    ];

    public static function getNavigationBadge(): ?string
    {
        $pendientes = Alerta::pendientes()->count();

        return $pendientes > 0 ? (string) $pendientes : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $zona = config('vehiculos.zona_horaria');

        return $table
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m H:i', $zona),
                TextColumn::make('tipo')->badge()->color('warning')
                    ->formatStateUsing(fn (string $state): string => self::TIPOS[$state] ?? $state),
                TextColumn::make('mensaje')->wrap(),
                TextColumn::make('viaje_id')->label('Viaje')->prefix('#')->placeholder('—')
                    ->url(fn (Alerta $record): ?string => $record->viaje_id
                        ? ViajeResource::getUrl('view', ['record' => $record->viaje_id])
                        : null),
                TextColumn::make('resuelta_en')->label('Resuelta')->dateTime('d/m H:i', $zona)->placeholder('Pendiente'),
            ])
            // Pendientes primero y, dentro de cada grupo, las más nuevas arriba.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('resuelta_en IS NOT NULL')
                ->orderByDesc('created_at'))
            ->filters([
                TernaryFilter::make('resuelta_en')
                    ->label('Resuelta')
                    ->nullable(),
            ])
            ->recordActions([
                Action::make('resolver')
                    ->label('Marcar resuelta')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->requiresConfirmation()
                    ->visible(fn (Alerta $record): bool => $record->resuelta_en === null)
                    ->action(fn (Alerta $record) => $record->update(['resuelta_en' => now()])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlertas::route('/'),
        ];
    }
}
