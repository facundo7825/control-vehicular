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
    /** Error de la app al pedir con un viaje en marcha (le habla al solicitante; el panel lo traduce). */
    public const YA_TIENE_VIAJE = 'Ya tenés un viaje en curso.';

    public function __construct(
        private Despachador $despachador,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
        private Parametros $parametros,
        private DisponibilidadReservas $disponibilidad,
        private AvisosReserva $avisosReserva,
    ) {}

    /** Un inmediato se puede reasignar hasta que empieza el viaje con el pasajero (spec 5.6). */
    private const REASIGNABLES_INMEDIATO = [
        EstadoViaje::Buscando, EstadoViaje::Ofrecido, EstadoViaje::Aceptado,
        EstadoViaje::EnCamino, EstadoViaje::Llego, EstadoViaje::SinChofer,
    ];

    /** Una reserva, mientras el chofer no haya salido. */
    private const REASIGNABLES_RESERVA = [
        EstadoViaje::Buscando, EstadoViaje::Ofrecido, EstadoViaje::Aceptado, EstadoViaje::SinChofer,
    ];

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
                throw new ReglaNegocio(self::YA_TIENE_VIAJE);
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

            $this->expirarOfertasPendientes($viaje->id);
        }, attempts: 3);

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    /**
     * Spec 5.6: el admin cancela desde el panel cualquier viaje que no haya terminado, incluidos los
     * obligatorios y los que están en curso (transición exclusiva del admin en MaquinaEstadosViaje).
     */
    public function cancelarPorAdmin(Viaje $viaje, Usuario $admin, string $motivo): Viaje
    {
        if (! $admin->esAdmin()) {
            throw new AccionNoPermitida('Solo un administrador puede cancelar desde el panel.');
        }
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new ReglaNegocio('Indicá el motivo de la cancelación.');
        }

        DB::transaction(function () use ($viaje, $motivo) {
            // Se valida la fila bloqueada: el chofer o el solicitante pueden haberlo cambiado recién.
            $viaje->setRawAttributes(Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);

            if (! self::cancelablePorAdmin($viaje)) {
                throw new ReglaNegocio($viaje->estado === EstadoViaje::Cancelado
                    ? 'El viaje ya estaba cancelado.'
                    : 'El viaje ya terminó; no se puede cancelar.');
            }

            $this->maquina->transicionarComoAdmin($viaje, EstadoViaje::Cancelado, [
                'cancelado_por' => 'admin',
                'motivo_cancelacion' => $motivo,
            ]);

            $this->expirarOfertasPendientes($viaje->id);
        }, attempts: 3);

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    /** ¿El panel ofrece "Cancelar" para este viaje? Todo lo que no terminó (sin_chofer ya es final). */
    public static function cancelablePorAdmin(Viaje $viaje): bool
    {
        return ! in_array($viaje->estado, [EstadoViaje::Finalizado, EstadoViaje::Cancelado, EstadoViaje::SinChofer], true);
    }

    /**
     * Spec 5.6: el admin asigna el viaje a otro chofer, sin oferta, sea o no obligatorio.
     * Inmediato: el chofer tiene que estar libre ahora. Reserva: la franja tiene que estar libre en su agenda.
     */
    public function reasignarPorAdmin(Viaje $viaje, Usuario $chofer): Viaje
    {
        return $this->asignarDirecto($viaje, $chofer, soloSinChofer: false);
    }

    /**
     * El admin asigna a mano un viaje que todavía no tiene chofer (buscando, ofrecido o sin chofer), sin oferta.
     * Mismas reglas y avisos que reasignar: vencen las ofertas pendientes y, si estaba sin chofer, la máquina
     * de estados resuelve la alerta del panel.
     */
    public function asignarPorAdmin(Viaje $viaje, Usuario $chofer): Viaje
    {
        return $this->asignarDirecto($viaje, $chofer, soloSinChofer: true);
    }

    private function asignarDirecto(Viaje $viaje, Usuario $chofer, bool $soloSinChofer): Viaje
    {
        $esReserva = DB::transaction(function () use ($viaje, $chofer, $soloSinChofer) {
            // Mismo orden de bloqueo que Asignador (viaje, luego chofer): compite en igualdad con
            // cualquier otra asignación a ese chofer.
            $viaje->setRawAttributes(Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $c = Usuario::whereKey($chofer->id)->lockForUpdate()->first();

            if (! $c?->esChofer() || ! $c->activo) {
                throw new ReglaNegocio('El chofer elegido no existe o no está activo.');
            }
            if ($viaje->chofer_id === $c->id) {
                throw new ReglaNegocio('El viaje ya está asignado a ese chofer.');
            }

            $esReserva = $viaje->tipo === TipoViaje::Reserva;
            if ($soloSinChofer && ! self::asignable($viaje)) {
                throw new ReglaNegocio('El viaje ya tiene chofer o terminó; no se puede asignar.');
            }
            if (! self::reasignable($viaje)) {
                throw new ReglaNegocio($esReserva
                    ? 'La reserva ya comenzó o terminó; no se puede reasignar.'
                    : 'El viaje ya comenzó o terminó; no se puede reasignar.');
            }

            if ($esReserva) {
                $libre = $this->disponibilidad->estaDisponible(
                    $c->id,
                    $viaje->programado_para,
                    $viaje->duracion_estimada_min ?? $this->parametros->entero('duracion_reserva_por_defecto_min'),
                    excluirViajeId: $viaje->id,
                    bloquear: true,
                );
                if (! $libre) {
                    throw new ReglaNegocio('El chofer tiene otra reserva en ese horario.');
                }
                // Una reserva inminente o ya vencida se atiende ya: el chofer tiene que estar libre ahora,
                // si no quedaría con dos viajes activos a la vez.
                $inminente = $viaje->programado_para
                    ->lte(now()->addMinutes($this->parametros->entero('bloqueo_antes_reserva_min')));
                if ($inminente && $this->estados->estado($c) !== EstadoChofer::Libre) {
                    throw new ReglaNegocio('La reserva empieza pronto y el chofer elegido no está libre ahora.');
                }
                $vehiculoId = null; // se toma del turno al salir (en_camino), como en toda reserva
            } else {
                if ($this->estados->estado($c) !== EstadoChofer::Libre) {
                    throw new ReglaNegocio('El chofer elegido no está libre.');
                }
                $vehiculoId = $c->turnoAbierto()->value('vehiculo_id');
            }

            // Primero la máquina (así avisa al chofer que tenía la oferta) y después se vence la oferta.
            $soloSinChofer
                ? $this->maquina->asignar($viaje, $c->id, $vehiculoId)
                : $this->maquina->reasignar($viaje, $c->id, $vehiculoId);
            $this->expirarOfertasPendientes($viaje->id);

            return $esReserva;
        }, attempts: 3);

        $viaje->refresh();

        if ($esReserva) {
            // Los recordatorios y la alerta del chofer anterior quedan sin efecto por sigueReservadaPara().
            $this->avisosReserva->programar($viaje);
        }

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    /** ¿El panel ofrece "Reasignar" para este viaje? (se vuelve a verificar con la fila bloqueada) */
    public static function reasignable(Viaje $viaje): bool
    {
        return in_array($viaje->estado, $viaje->tipo === TipoViaje::Reserva
            ? self::REASIGNABLES_RESERVA
            : self::REASIGNABLES_INMEDIATO, true);
    }

    /** ¿El panel ofrece "Asignar chofer"? El viaje todavía no tiene chofer (se vuelve a verificar con la fila bloqueada). */
    public static function asignable(Viaje $viaje): bool
    {
        return in_array($viaje->estado, [EstadoViaje::Buscando, EstadoViaje::Ofrecido, EstadoViaje::SinChofer], true);
    }

    /** Vence las ofertas que seguían abiertas: si el chofer responde tarde, recibe "La oferta ya no está vigente". */
    private function expirarOfertasPendientes(int $viajeId): void
    {
        OfertaViaje::where('viaje_id', $viajeId)
            ->where('resultado', ResultadoOferta::Pendiente)
            ->update(['resultado' => ResultadoOferta::Expirada, 'respondido_en' => now()]);
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
