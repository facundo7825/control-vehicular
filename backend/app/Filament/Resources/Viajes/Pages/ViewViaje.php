<?php

namespace App\Filament\Resources\Viajes\Pages;

use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\ServicioViaje;
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
            Action::make('reasignar')
                ->label('Reasignar')
                ->icon(Heroicon::OutlinedArrowPath)
                ->modalHeading('Reasignar a otro chofer')
                ->modalDescription('Se asigna directo, sin oferta, y se avisa al chofer anterior, al nuevo y al solicitante.')
                ->visible(fn (Viaje $record): bool => ServicioViaje::reasignable($record))
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
