<?php

namespace App\Filament\Pages;

use App\Models\Parametro;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Parámetros de la spec 5.7. Los valores por defecto están en config('vehiculos.parametros'); un valor
 * cambiado se guarda en la tabla `parametros` y Parametros::entero() lo lee en cada llamada (sin caché),
 * así que rige desde la operación siguiente. "Restablecer" borra la fila y vuelve al valor por defecto.
 */
class ConfiguracionParametros extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Parámetros';

    protected static ?string $title = 'Parámetros';

    protected static ?string $slug = 'parametros';

    protected static ?int $navigationSort = 50;

    private const DESCRIPCIONES = [
        'oferta_segundos' => 'Segundos para responder un pedido inmediato',
        'candidatos_distance_matrix' => 'Choferes más cercanos evaluados con Distance Matrix',
        'bloqueo_antes_reserva_min' => 'Minutos antes de una reserva en que el chofer deja de recibir inmediatos',
        'colchon_reservas_min' => 'Minutos de colchón entre reservas del mismo chofer',
        'anticipacion_minima_reserva_min' => 'Anticipación mínima para reservar (minutos)',
        'plazo_respuesta_reserva_min' => 'Minutos para responder una solicitud de reserva',
        'margen_duracion_reserva_min' => 'Minutos que se suman a la duración estimada de una reserva',
        'duracion_reserva_por_defecto_min' => 'Duración de una reserva si Google no la estima (minutos)',
        'recordatorio_reserva_1_min' => 'Primer recordatorio de reserva (minutos antes)',
        'recordatorio_reserva_2_min' => 'Segundo recordatorio de reserva (minutos antes)',
        'alerta_sin_turno_min' => 'Alerta si el chofer no inició turno (minutos antes de la reserva)',
        'sin_senal_min' => 'Minutos sin ubicación para pasar a "sin señal"',
        'no_disponible_min' => 'Minutos sin ubicación para alertar al panel durante un viaje',
        'gps_turno_seg' => 'Intervalo de GPS en turno (segundos)',
        'gps_viaje_seg' => 'Intervalo de GPS en viaje (segundos)',
        'retencion_recorrido_dias' => 'Días que se guarda el recorrido de un viaje',
    ];

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->filas())
            ->paginated(false)
            ->columns([
                TextColumn::make('descripcion')->label('Parámetro')->description(fn (array $record): string => $record['clave']),
                TextColumn::make('por_defecto')->label('Por defecto'),
                TextColumn::make('actual')->label('Valor actual')->weight('bold'),
                IconColumn::make('personalizado')->label('Cambiado')->boolean(),
            ])
            ->recordActions([
                Action::make('editar')
                    ->label('Cambiar')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(fn (array $record): string => $record['descripcion'])
                    ->fillForm(fn (array $record): array => ['valor' => $record['actual']])
                    ->schema([
                        TextInput::make('valor')
                            ->label('Valor')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100000),
                    ])
                    ->action(function (array $record, array $data): void {
                        Parametro::updateOrCreate(['clave' => $record['clave']], ['valor' => (string) (int) $data['valor']]);
                        $this->flushCachedTableRecords();
                        Notification::make()->success()->title('Parámetro guardado')->send();
                    }),
                Action::make('restablecer')
                    ->label('Restablecer')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (array $record): bool => $record['personalizado'])
                    ->action(function (array $record): void {
                        Parametro::whereKey($record['clave'])->delete();
                        $this->flushCachedTableRecords();
                        Notification::make()->success()->title('Se restableció el valor por defecto')->send();
                    }),
            ]);
    }

    /** @return array<string, array{clave: string, descripcion: string, por_defecto: int, actual: int, personalizado: bool}> */
    public function filas(): array
    {
        $guardados = Parametro::pluck('valor', 'clave');

        return collect(config('vehiculos.parametros'))
            ->mapWithKeys(fn (int $defecto, string $clave): array => [$clave => [
                'clave' => $clave,
                'descripcion' => self::DESCRIPCIONES[$clave] ?? $clave,
                'por_defecto' => $defecto,
                'actual' => (int) ($guardados[$clave] ?? $defecto),
                'personalizado' => $guardados->has($clave),
            ]])
            ->all();
    }
}
