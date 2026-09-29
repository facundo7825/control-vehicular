<?php

namespace App\Listeners;

use App\Enums\EstadoViaje;
use App\Events\OfertaCreada;
use App\Events\ViajeActualizado;
use App\Models\Usuario;
use App\Notificaciones\Notificador;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Traduce eventos de viaje a notificaciones push. */
class AvisosViaje implements ShouldQueue
{
    public function __construct(private Notificador $push) {}

    public function handleOfertaCreada(OfertaCreada $e): void
    {
        $viaje = $e->oferta->viaje;

        $this->push->enviar($e->oferta->chofer, 'Nuevo pedido de viaje',
            'Hacia '.($viaje->destino_direccion ?? 'destino marcado en el mapa').'. Respondé antes de que venza.',
            ['tipo' => 'oferta', 'oferta_id' => $e->oferta->id, 'viaje_id' => $viaje->id]);
    }

    public function handleViajeActualizado(ViajeActualizado $e): void
    {
        $v = $e->viaje->loadMissing(['chofer', 'vehiculo', 'solicitante']);
        $datos = ['tipo' => 'viaje', 'viaje_id' => $v->id, 'estado' => $v->estado->value];

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
}
