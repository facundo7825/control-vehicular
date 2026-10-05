<?php

namespace App\Filament\Resources\Usuarios\Pages;

use App\Enums\RolUsuario;
use App\Filament\Resources\EventosAsistencia\EventoAsistenciaResource;
use App\Filament\Resources\Usuarios\RelationManagers\TurnosRelationManager;
use App\Filament\Resources\Usuarios\UsuarioResource;
use App\Models\EventoAsistencia;
use App\Models\Usuario;
use App\Servicios\ServicioAsistencia;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditUsuario extends EditRecord
{
    protected static string $resource = UsuarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Para la demo: procesa un fichaje con la hora actual como si llegara del control de asistencia.
            Action::make('simularFichaje')
                ->label('Simular fichaje')
                ->icon(Heroicon::OutlinedFingerPrint)
                ->color('gray')
                ->modalDescription('Procesa un fichaje con la hora actual, como si llegara del control de asistencia.')
                ->modalSubmitActionLabel('Fichar')
                ->schema([
                    ToggleButtons::make('tipo')
                        ->options(EventoAsistenciaResource::TIPOS)
                        ->default(EventoAsistencia::ENTRADA)
                        ->inline()
                        ->required(),
                ])
                ->visible(fn (): bool => $this->getRecord()->esChofer() && filled($this->getRecord()->id_externo))
                ->action(function (array $data, ServicioAsistencia $asistencia): void {
                    $r = $asistencia->procesar($this->getRecord()->id_externo, $data['tipo']);
                    $tipo = mb_strtolower(EventoAsistenciaResource::TIPOS[$data['tipo']]);

                    Notification::make()
                        ->title("Fichaje de {$tipo}: ".mb_strtolower(EventoAsistenciaResource::RESULTADOS[$r['resultado']] ?? $r['resultado']))
                        ->body($r['motivo'])
                        ->status(match (EventoAsistenciaResource::colorResultado($r['resultado'])) {
                            'success' => 'success',
                            'warning' => 'warning',
                            default => 'info',
                        })
                        ->send();

                    // Los turnos del chofer se ven en el relation manager, que es otro componente.
                    $this->dispatch(TurnosRelationManager::EVENTO_ACTUALIZAR);
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Usuario $usuario */
        $usuario = $this->getRecord();

        // Los campos deshabilitados no se guardan, pero se refuerza por si llegan igual.
        if ($usuario->is(Filament::auth()->user())) {
            unset($data['rol'], $data['activo']);

            return $data;
        }

        $rol = $data['rol'] instanceof RolUsuario ? $data['rol'] : RolUsuario::from($data['rol']);
        $dejaDeManejar = $usuario->esChofer() && ($rol !== RolUsuario::Chofer || ! $data['activo']);

        if ($dejaDeManejar && $usuario->tieneTrabajoDeChofer()) {
            Notification::make()
                ->danger()
                ->title('El chofer tiene un turno abierto o viajes asignados.')
                ->body('Cerrá su turno y reasigná o cancelá sus viajes antes de quitarle el rol o desactivarlo.')
                ->send();

            $this->halt();
        }

        return $data;
    }
}
