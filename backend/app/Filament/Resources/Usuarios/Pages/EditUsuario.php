<?php

namespace App\Filament\Resources\Usuarios\Pages;

use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Filament\Resources\Usuarios\UsuarioResource;
use App\Models\Usuario;
use App\Models\Viaje;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUsuario extends EditRecord
{
    protected static string $resource = UsuarioResource::class;

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

        if ($dejaDeManejar && $this->tieneTrabajoPendiente($usuario)) {
            Notification::make()
                ->danger()
                ->title('El chofer tiene un turno abierto o viajes asignados.')
                ->body('Cerrá su turno y reasigná o cancelá sus viajes antes de quitarle el rol o desactivarlo.')
                ->send();

            $this->halt();
        }

        return $data;
    }

    private function tieneTrabajoPendiente(Usuario $chofer): bool
    {
        return $chofer->turnoAbierto()->exists()
            || Viaje::where('chofer_id', $chofer->id)->whereIn('estado', EstadoViaje::conChofer())->exists();
    }
}
