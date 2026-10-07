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
use App\Servicios\ServicioViajesLargos;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * "Nuevo viaje": el admin pide un viaje o una reserva a nombre de otra persona, o carga un viaje largo. No crea
 * el registro a mano: usa los mismos servicios que la app con el solicitante elegido (obligatorio según su cargo,
 * despacho); el viaje largo, ServicioViajesLargos, que lo asigna directo.
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

    /** Un viaje largo se asigna directo (aceptado) al chofer y al vehículo elegidos, sin modo. */
    private const CAMPOS_LARGO = [
        'solicitante_id', 'chofer_id', 'vehiculo_id', 'programado_para', 'regreso_estimado', 'pasajeros', 'motivo',
        'origen_lat', 'origen_lng', 'origen_direccion',
        'destino_lat', 'destino_lng', 'destino_direccion',
    ];

    protected function handleRecordCreation(array $data): Model
    {
        $solicitante = Usuario::where('rol', RolUsuario::Solicitante)->where('activo', true)->findOrFail($data['solicitante_id']);
        $datos = Arr::only($data, self::CAMPOS);

        try {
            return match ($data['tipo']) {
                TipoViaje::Largo->value => app(ServicioViajesLargos::class)->crear(Arr::only($data, self::CAMPOS_LARGO), Filament::auth()->user()),
                TipoViaje::Reserva->value => app(ServicioReservas::class)->crear($solicitante, $datos),
                default => app(ServicioViaje::class)->pedir($solicitante, $datos),
            };
        } catch (ReglaNegocio|AccionNoPermitida $e) {
            // Los textos de los servicios le hablan al solicitante en la app; acá el que lee es el admin.
            $mensaje = $e->getMessage() === ServicioViaje::YA_TIENE_VIAJE
                ? 'El solicitante ya tiene un viaje en curso.'
                : $e->getMessage();
            Notification::make()->danger()->title('No se pudo crear el viaje')->body($mensaje)->send();

            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Viaje creado';
    }
}
