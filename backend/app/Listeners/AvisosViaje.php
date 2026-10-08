<?php

namespace App\Listeners;

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Events\OfertaCreada;
use App\Events\ViajeActualizado;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Support\HoraLocal;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Traduce eventos de viaje a notificaciones push. */
class AvisosViaje implements ShouldQueue
{
    public function __construct(private Notificador $push) {}

    public function handleOfertaCreada(OfertaCreada $e): void
    {
        $viaje = $e->oferta->viaje;
        $destino = $viaje->destino_direccion ?? 'destino marcado en el mapa';

        if ($viaje->tipo === TipoViaje::Reserva) {
            // tipo distinto: la app la muestra en la agenda, no en la pantalla completa de oferta.
            $this->push->enviar($e->oferta->chofer, 'Solicitud de reserva para '.$viaje->horaProgramadaLocal(),
                "Hacia $destino. Respondé antes del ".HoraLocal::formatear($e->oferta->vence_en).'.',
                ['tipo' => 'oferta_reserva', 'oferta_id' => $e->oferta->id, 'viaje_id' => $viaje->id]);

            return;
        }

        $this->push->enviar($e->oferta->chofer, 'Nuevo pedido de viaje',
            "Hacia $destino. Respondé antes de que venza.",
            ['tipo' => 'oferta', 'oferta_id' => $e->oferta->id, 'viaje_id' => $viaje->id]);
    }

    public function handleViajeActualizado(ViajeActualizado $e): void
    {
        if ($e->soloDatos) {
            return;
        }

        $v = $e->viaje->loadMissing(['chofer', 'vehiculo', 'solicitante']);
        $datos = ['tipo' => 'viaje', 'viaje_id' => $v->id, 'estado' => $v->estado->value];

        if ($e->porAdmin) {
            $this->avisarAccionDelAdmin($v, $e->choferAnteriorId, $datos);

            return;
        }

        if ($v->tipo === TipoViaje::Reserva && $this->avisarReserva($v, $e->choferAnteriorId, $datos)) {
            return;
        }

        switch ($v->estado) {
            case EstadoViaje::Aceptado:
                if (! $v->chofer) {
                    break;
                }
                if ($v->obligatorio) {
                    $this->push->enviar($v->chofer, 'Viaje asignado',
                        'Tenés un viaje obligatorio asignado.', $datos);
                }
                $this->push->enviar($v->solicitante, 'Tu auto está confirmado',
                    "Te busca {$v->chofer->nombre} en {$v->vehiculo?->marca} {$v->vehiculo?->modelo} ({$v->vehiculo?->patente}).", $datos);
                break;

            case EstadoViaje::Llego:
                $this->push->enviar($v->solicitante, 'Tu auto llegó', 'El chofer te está esperando.', $datos);
                break;

            case EstadoViaje::SinChofer:
                $this->push->enviar($v->solicitante, 'No hay choferes disponibles',
                    'Podés volver a intentar o elegir otro chofer.', $datos);
                break;

            case EstadoViaje::Cancelado:
                if ($v->cancelado_por === 'solicitante' && $v->chofer) {
                    $this->push->enviar($v->chofer, 'Viaje cancelado', 'El solicitante canceló el viaje.', $datos);
                }
                break;

            case EstadoViaje::Buscando:
                if ($e->choferAnteriorId) {
                    $this->push->enviar($v->solicitante, 'Tu chofer canceló',
                        'Estamos buscando otro chofer.', $datos);
                }
                break;

            default:
                break;
        }
    }

    /** Textos propios de las reservas. Devuelve false si el cambio se avisa igual que en un inmediato. */
    private function avisarReserva(Viaje $v, ?int $choferAnteriorId, array $datos): bool
    {
        $cuando = $v->horaProgramadaLocal();

        switch ($v->estado) {
            case EstadoViaje::Aceptado:
                if (! $v->chofer) {
                    return true;
                }
                if ($v->obligatorio) {
                    $this->push->enviar($v->chofer, 'Reserva asignada', "Tenés una reserva obligatoria el $cuando.", $datos);
                }
                $this->push->enviar($v->solicitante, "Reserva confirmada para $cuando", "Te llevará {$v->chofer->nombre}.", $datos);

                return true;

            case EstadoViaje::SinChofer:
                if ($choferAnteriorId) {
                    $this->push->enviar($v->solicitante, 'Tu chofer canceló la reserva',
                        "Elegí otro chofer para el viaje del $cuando.", $datos);
                } else {
                    $this->push->enviar($v->solicitante, 'Tu reserva no fue aceptada',
                        "Elegí otro chofer para el viaje del $cuando.", $datos);
                }

                return true;

            case EstadoViaje::Cancelado:
                if ($v->cancelado_por === 'solicitante' && $v->chofer) {
                    $this->push->enviar($v->chofer, 'Reserva cancelada', "El solicitante canceló la reserva del $cuando.", $datos);
                }

                return true;

            default:
                return false; // en_camino, llego, etc.: mismo aviso que un viaje inmediato
        }
    }

    /** Cancelación o reasignación hecha desde el panel (spec 5.6). */
    private function avisarAccionDelAdmin(Viaje $v, ?int $choferAnteriorId, array $datos): void
    {
        if ($v->tipo === TipoViaje::Largo) {
            $this->avisarViajeLargo($v, $choferAnteriorId, $datos);

            return;
        }

        $esReserva = $v->tipo === TipoViaje::Reserva;
        $cuando = $v->horaProgramadaLocal();
        $cual = $esReserva ? "la reserva del $cuando" : 'el viaje';

        if ($v->estado === EstadoViaje::Cancelado) {
            $titulo = $esReserva ? 'Reserva cancelada' : 'Viaje cancelado';
            $this->push->enviar($v->solicitante, $titulo, "Un administrador canceló $cual. Motivo: {$v->motivo_cancelacion}", $datos);
            if ($v->chofer) {
                $this->push->enviar($v->chofer, $titulo, "Un administrador canceló $cual.", $datos);
            }

            return;
        }

        // Reasignación: el viaje quedó aceptado con otro chofer.
        if ($choferAnteriorId && $anterior = Usuario::find($choferAnteriorId)) {
            $this->push->enviar($anterior, $esReserva ? 'Reserva reasignada' : 'Viaje reasignado',
                "Un administrador le asignó $cual a otro chofer.", $datos);
        }
        $this->push->enviar($v->chofer, $esReserva ? 'Reserva asignada' : 'Viaje asignado',
            $esReserva ? "Un administrador te asignó una reserva el $cuando." : 'Un administrador te asignó un viaje.', $datos);
        $this->push->enviar($v->solicitante, $esReserva ? "Reserva confirmada para $cuando" : 'Tu auto está confirmado',
            "Ahora te lleva {$v->chofer->nombre}.", $datos);
    }

    /** Viaje largo: el encargado lo crea (asignado), le cambia el chofer o el vehículo, o lo cancela. */
    private function avisarViajeLargo(Viaje $v, ?int $choferAnteriorId, array $datos): void
    {
        $cuando = $v->horaProgramadaLocal();

        if ($v->estado === EstadoViaje::Cancelado) {
            $this->push->enviar($v->solicitante, 'Viaje largo cancelado',
                "Un administrador canceló el viaje largo del $cuando. Motivo: {$v->motivo_cancelacion}", $datos);
            if ($v->chofer) {
                $this->push->enviar($v->chofer, 'Viaje largo cancelado', "Un administrador canceló el viaje largo del $cuando.", $datos);
            }

            return;
        }

        if ($choferAnteriorId && $anterior = Usuario::find($choferAnteriorId)) {
            $this->push->enviar($anterior, 'Viaje largo reasignado',
                "Un administrador le asignó el viaje largo del $cuando a otro chofer.", $datos);
        }
        $destino = $v->destino_direccion ?? 'destino marcado en el mapa';
        $vehiculo = "{$v->vehiculo?->marca} {$v->vehiculo?->modelo} ({$v->vehiculo?->patente})";
        $this->push->enviar($v->chofer, 'Viaje largo asignado', "Salida el $cuando hacia $destino en $vehiculo.", $datos);
        $this->push->enviar($v->solicitante, "Viaje largo confirmado para $cuando",
            "Te lleva {$v->chofer->nombre} en $vehiculo.", $datos);
    }
}
