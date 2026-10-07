<?php

namespace App\Filament\Resources\Viajes\Pages;

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\Servicios\ServicioViaje;
use App\Servicios\ServicioViajesLargos;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewViaje extends ViewRecord
{
    protected static string $resource = ViajeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViajeResource::accionAsignar()
                ->action(function (Viaje $record, array $data): void {
                    $this->ejecutar(
                        fn () => app(ServicioViaje::class)->asignarPorAdmin($record, Usuario::findOrFail($data['chofer_id'])),
                        'Chofer asignado',
                    );
                }),
            Action::make('reasignar')
                ->label('Reasignar')
                ->icon(Heroicon::OutlinedArrowPath)
                ->modalHeading('Reasignar a otro chofer')
                ->modalDescription('Se asigna directo, sin oferta, y se avisa al chofer anterior, al nuevo y al solicitante.')
                // Si todavía no tiene chofer, la acción es "Asignar chofer".
                ->visible(fn (Viaje $record): bool => ServicioViaje::reasignable($record) && ! ServicioViaje::asignable($record))
                ->schema([
                    Select::make('chofer_id')
                        ->label('Chofer')
                        ->options(fn (Viaje $record): array => ViajeResource::choferesElegibles($record))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (Viaje $record, array $data): void {
                    $this->ejecutar(
                        fn () => app(ServicioViaje::class)->reasignarPorAdmin($record, Usuario::findOrFail($data['chofer_id'])),
                        'Viaje reasignado',
                    );
                }),
            Action::make('cambiarVehiculo')
                ->label('Cambiar vehículo')
                ->icon(Heroicon::OutlinedTruck)
                ->modalHeading('Cambiar el vehículo del viaje largo')
                ->modalDescription('El chofer sigue siendo el mismo. Se avisa al chofer y al solicitante.')
                // Mientras el chofer no salió: después, el vehículo ya está en su turno.
                ->visible(fn (Viaje $record): bool => $record->tipo === TipoViaje::Largo && $record->estado === EstadoViaje::Aceptado)
                ->schema([
                    Select::make('vehiculo_id')
                        ->label('Vehículo')
                        ->options(fn (Viaje $record): array => array_diff_key(
                            ViajeResource::vehiculosLibresParaViajeLargo(
                                $record->programado_para, (int) $record->duracion_estimada_min, $record->chofer_id, $record->id,
                            ),
                            [$record->vehiculo_id => true],
                        ))
                        ->helperText('Vehículos que no están en otro viaje largo en la franja.')
                        ->searchable()
                        ->required(),
                ])
                ->action(function (Viaje $record, array $data): void {
                    $this->ejecutar(
                        fn () => app(ServicioViajesLargos::class)->reasignar($record, $record->chofer, Vehiculo::findOrFail($data['vehiculo_id'])),
                        'Vehículo cambiado',
                    );
                }),
            Action::make('cancelar')
                ->label('Cancelar')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancelar el viaje')
                ->modalDescription('Se avisa al solicitante y al chofer. No se puede deshacer.')
                ->visible(fn (Viaje $record): bool => ServicioViaje::cancelablePorAdmin($record))
                ->schema([
                    Textarea::make('motivo')
                        ->label('Motivo')
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (Viaje $record, array $data): void {
                    $this->ejecutar(
                        fn () => app(ServicioViaje::class)->cancelarPorAdmin($record, Filament::auth()->user(), $data['motivo']),
                        'Viaje cancelado',
                    );
                }),
        ];
    }

    /** Las reglas de negocio llegan como notificación, no como error 500. */
    private function ejecutar(callable $operacion, string $exito): void
    {
        try {
            $operacion();
        } catch (ReglaNegocio|AccionNoPermitida $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } finally {
            // Se muestre lo que se muestre, la página refleja el estado actual del viaje.
            $this->getRecord()->refresh();
        }

        Notification::make()->success()->title($exito)->send();
    }
}
