<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Models\CargoPrioritario;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;

class ServicioViaje
{
    public function __construct(
        private Despachador $despachador,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
        private Parametros $parametros,
    ) {}

    public function pedir(Usuario $solicitante, array $datos): Viaje
    {
        $modo = ModoViaje::from($datos['modo']);
        $chofer = null;
        if ($modo === ModoViaje::Especifico) {
            $chofer = Usuario::where('rol', RolUsuario::Chofer)->find($datos['chofer_id'])
                ?? throw new ReglaNegocio('El chofer elegido no existe.');
            if ($this->estados->estado($chofer) !== EstadoChofer::Libre) {
                throw new ReglaNegocio('El chofer elegido no está disponible.');
            }
        }

        $viaje = DB::transaction(function () use ($solicitante, $datos, $modo) {
            // Bloquea al solicitante para que un doble toque o un reintento no creen dos viajes.
            Usuario::whereKey($solicitante->id)->lockForUpdate()->first();

            $enProgreso = Viaje::where('solicitante_id', $solicitante->id)
                ->where('tipo', TipoViaje::Inmediato)
                ->whereIn('estado', EstadoViaje::enProgreso())
                ->exists();
            if ($enProgreso) {
                throw new ReglaNegocio('Ya tenés un viaje en curso.');
            }

            return Viaje::create([
                'solicitante_id' => $solicitante->id,
                'tipo' => TipoViaje::Inmediato,
                'modo' => $modo,
                'obligatorio' => CargoPrioritario::esObligatorio($solicitante->cargo),
                'origen_lat' => $datos['origen_lat'],
                'origen_lng' => $datos['origen_lng'],
                'origen_direccion' => $datos['origen_direccion'] ?? null,
                'destino_lat' => $datos['destino_lat'],
                'destino_lng' => $datos['destino_lng'],
                'destino_direccion' => $datos['destino_direccion'] ?? null,
                'motivo' => $datos['motivo'] ?? null,
                'estado' => EstadoViaje::Buscando,
            ]);
        }, attempts: 3);

        $chofer
            ? $this->despachador->pedirA($viaje, $chofer)
            : $this->despachador->despachar($viaje);

        return $viaje->refresh()->load(['chofer', 'vehiculo', 'solicitante']);
    }

    private const PASOS_CHOFER = [EstadoViaje::EnCamino, EstadoViaje::Llego, EstadoViaje::EnCurso, EstadoViaje::Finalizado];

    public function avanzar(Viaje $viaje, Usuario $chofer, EstadoViaje $hacia): Viaje
    {
        if ($viaje->chofer_id !== $chofer->id) {
            throw new AccionNoPermitida('Este viaje no es tuyo.');
        }
        if (! in_array($hacia, self::PASOS_CHOFER, true)) {
            throw new ReglaNegocio('Estado no válido para el chofer.');
        }

        if ($hacia === EstadoViaje::EnCamino && $viaje->tipo === TipoViaje::Reserva) {
            $this->salirHaciaReserva($viaje, $chofer);
        } else {
            $this->maquina->transicionar($viaje, $hacia);
        }

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    /** Spec 5.4 paso 7: la reserva arranca como un viaje normal, pero no antes de tiempo, sin turno ni con otro viaje. */
    private function salirHaciaReserva(Viaje $viaje, Usuario $chofer): void
    {
        DB::transaction(function () use ($viaje, $chofer) {
            // Mismo orden de bloqueo que Asignador (viaje, luego chofer): mientras sale, no se le asigna un inmediato.
            $actual = Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail();
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();
            $viaje->setRawAttributes($actual->getAttributes(), true);

            if ($viaje->chofer_id !== $chofer->id) {
                throw new AccionNoPermitida('Este viaje no es tuyo.');
            }
            if ($viaje->estado !== EstadoViaje::Aceptado) {
                // Repetido (ya salió): no-op. Cancelada o terminada: la máquina lo rechaza.
                $this->maquina->transicionar($viaje, EstadoViaje::EnCamino);

                return;
            }

            $desde = $viaje->programado_para->copy()->subMinutes($this->parametros->entero('bloqueo_antes_reserva_min'));
            if (now()->lt($desde)) {
                throw new ReglaNegocio('Podés salir hacia esta reserva a partir de las '.HoraLocal::formatear($desde, 'H:i').'.');
            }

            $vehiculoId = $chofer->turnoAbierto()->value('vehiculo_id')
                ?? throw new ReglaNegocio('Iniciá tu turno para comenzar la reserva.');

            if (Viaje::activosDeChofer($chofer->id)->whereKeyNot($viaje->id)->exists()) {
                throw new ReglaNegocio('Terminá tu viaje actual antes de comenzar la reserva.');
            }

            $this->maquina->transicionar($viaje, EstadoViaje::EnCamino, ['vehiculo_id' => $vehiculoId]);
        }, attempts: 3);
    }

    public function cancelarPorSolicitante(Viaje $viaje, Usuario $solicitante, ?string $motivo): Viaje
    {
        if ($viaje->solicitante_id !== $solicitante->id) {
            throw new AccionNoPermitida('Este viaje no es tuyo.');
        }

        DB::transaction(function () use ($viaje, $motivo) {
            $this->maquina->transicionar($viaje, EstadoViaje::Cancelado, [
                'cancelado_por' => 'solicitante',
                'motivo_cancelacion' => $motivo,
            ]);

            OfertaViaje::where('viaje_id', $viaje->id)
                ->where('resultado', ResultadoOferta::Pendiente)
                ->update(['resultado' => ResultadoOferta::Expirada, 'respondido_en' => now()]);
        }, attempts: 3);

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    public function cancelarPorChofer(Viaje $viaje, Usuario $chofer, string $motivo): Viaje
    {
        DB::transaction(function () use ($viaje, $chofer, $motivo) {
            // Las validaciones se hacen sobre la fila bloqueada: el solicitante puede haber cancelado recién.
            $viaje->setRawAttributes(Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);

            if ($viaje->chofer_id !== $chofer->id) {
                throw new AccionNoPermitida('Este viaje no es tuyo.');
            }
            if ($viaje->obligatorio) {
                throw new AccionNoPermitida('Los viajes obligatorios solo puede cancelarlos un administrador.');
            }
            if (! in_array($viaje->estado, [EstadoViaje::Aceptado, EstadoViaje::EnCamino, EstadoViaje::Llego], true)) {
                throw new ReglaNegocio('El viaje ya no se puede cancelar.');
            }
            if ($viaje->tipo === TipoViaje::Reserva && $viaje->estado !== EstadoViaje::Aceptado) {
                throw new ReglaNegocio('La reserva ya comenzó; no se puede cancelar.');
            }

            // Queda registrado como rechazo: el despachador no volverá a ofrecérselo.
            OfertaViaje::create([
                'viaje_id' => $viaje->id,
                'chofer_id' => $chofer->id,
                'resultado' => ResultadoOferta::Rechazada,
                'ofrecido_en' => $viaje->aceptado_en ?? now(),
                'vence_en' => now(),
                'respondido_en' => now(),
                'motivo' => $motivo,
            ]);

            if ($viaje->tipo === TipoViaje::Reserva) {
                // Spec 5.6: la reserva no se reasigna sola; se avisa al solicitante para que elija otro chofer.
                $this->maquina->transicionar($viaje, EstadoViaje::SinChofer, [
                    'chofer_id' => null,
                    'vehiculo_id' => null,
                ]);

                return;
            }

            $this->maquina->transicionar($viaje, EstadoViaje::Buscando, [
                'chofer_id' => null,
                'vehiculo_id' => null,
                'modo' => ModoViaje::MasCercano,
            ]);
        }, attempts: 3);

        if ($viaje->tipo === TipoViaje::Inmediato) {
            $this->despachador->despachar($viaje);
        }

        return $viaje->refresh()->load(['chofer', 'vehiculo', 'solicitante']);
    }
}
