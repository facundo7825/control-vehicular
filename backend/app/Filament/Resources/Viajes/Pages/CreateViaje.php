<?php

namespace App\Filament\Resources\Viajes\Pages;

use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\Usuario;
use App\Servicios\ServicioReservas;
use App\Servicios\ServicioViaje;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * "Nuevo viaje": el admin pide un viaje o una reserva a nombre de otra persona. No crea el registro a mano:
 * usa los mismos servicios que la app con el solicitante elegido (obligatorio según su cargo, despacho).
 */
class CreateViaje extends CreateRecord
{
    protected static string $resource = ViajeResource::class;

    protected static ?string $title = 'Nuevo viaje';

    /** Los servicios manejan sus transacciones y despachan al terminar: no se envuelven en otra. */
    protected ?bool $hasDatabaseTransactions = false;

    private const CAMPOS = [
        'modo', 'chofer_id', 'motivo', 'programado_para',
        'origen_lat', 'origen_lng', 'origen_direccion',
        'destino_lat', 'destino_lng', 'destino_direccion',
    ];

    protected function handleRecordCreation(array $data): Model
    {
        $solicitante = Usuario::where('rol', RolUsuario::Solicitante)->where('activo', true)->findOrFail($data['solicitante_id']);
        $datos = Arr::only($data, self::CAMPOS);

        try {
            return $data['tipo'] === TipoViaje::Reserva->value
                ? app(ServicioReservas::class)->crear($solicitante, $datos)
                : app(ServicioViaje::class)->pedir($solicitante, $datos);
        } catch (ReglaNegocio|AccionNoPermitida $e) {
            Notification::make()->danger()->title('No se pudo crear el viaje')->body($e->getMessage())->send();

            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Viaje creado';
    }
}
