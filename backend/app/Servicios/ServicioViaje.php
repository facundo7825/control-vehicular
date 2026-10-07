<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ConflictoViaje;
use App\Excepciones\ReglaNegocio;
use App\Models\AccionViaje;
use App\Models\CargoPrioritario;
use App\Models\OfertaViaje;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\Support\HoraLocal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ServicioViaje
{
    /** Error de la app al pedir con un viaje en marcha (le habla al solicitante; el panel lo traduce). */
    public const YA_TIENE_VIAJE = 'Ya tenés un viaje en curso.';

    /** Un viaje largo lo asignó el encargado: ni el chofer ni el solicitante lo cancelan. */
    private const LARGO_SOLO_ENCARGADO = 'Los viajes largos solo puede cancelarlos o cambiarlos un administrador.';

    public function __construct(
        private Despachador $despachador,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
        private Parametros $parametros,
        private DisponibilidadReservas $disponibilidad,
        private AvisosReserva $avisosReserva,
        private CompletadorDirecciones $direcciones,
        private ServicioViajesLargos $largos,
    ) {}

    /** Un inmediato se puede reasignar hasta que empieza el viaje con el pasajero (spec 5.6). */
    private const REASIGNABLES_INMEDIATO = [
        EstadoViaje::Buscando, EstadoViaje::Ofrecido, EstadoViaje::Aceptado,
        EstadoViaje::EnCamino, EstadoViaje::Llego, EstadoViaje::SinChofer,
    ];

    /** Una reserva o un viaje largo, mientras el chofer no haya salido. */
    private const REASIGNABLES_RESERVA = [
        EstadoViaje::Buscando, EstadoViaje::Ofrecido, EstadoViaje::Aceptado, EstadoViaje::SinChofer,
    ];

    public function pedir(Usuario $solicitante, array $datos): Viaje
    {
        $modo = ModoViaje::from($datos['modo']);
        $chofer = null;
        if ($modo === ModoViaje::Especifico) {
            $chofer = Usuario::where('rol', RolUsuario::Chofer)->where('activo', true)->find($datos['chofer_id'])
                ?? throw new ReglaNegocio('El chofer elegido no existe.');
            if ($this->estados->estado($chofer) !== EstadoChofer::Libre) {
                throw new ReglaNegocio('El chofer elegido no está disponible.');
            }
        }

        // Antes de la transacción: la consulta de las direcciones que faltan no retiene ningún lock.
        $datos = $this->direcciones->completar($datos);

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

        $this->direcciones->reintentarSiFalta($viaje);

        $chofer
            ? $this->despachador->pedirA($viaje, $chofer)
            : $this->despachador->despachar($viaje);

        return $viaje->refresh()->load(['chofer', 'vehiculo', 'solicitante']);
    }

    private const PASOS_CHOFER = [EstadoViaje::EnCamino, EstadoViaje::Llego, EstadoViaje::EnCurso, EstadoViaje::Finalizado];

    /** Tolerancia de la hora del celular: hacia adelante y antes del paso anterior del viaje. */
    private const TOLERANCIA_MOMENTO_MIN = 2;

    /** Una acción guardada sin señal se acepta hasta este tiempo después. */
    private const ANTIGUEDAD_MAXIMA_MOMENTO_H = 24;

    private const ACCION_DE_OTRO_VIAJE = 'Esa acción ya se registró en otro viaje.';

    /**
     * El chofer avanza el viaje. La app puede mandar la acción tarde (la tocó sin señal): `$momento` es cuándo la
     * tocó y `$idAccion` (uuid de la app) hace que un reenvío de una acción ya aplicada no la repita.
     */
    public function avanzar(
        Viaje $viaje, Usuario $chofer, EstadoViaje $hacia, ?Carbon $momento = null, ?string $idAccion = null,
    ): Viaje {
        if (! in_array($hacia, self::PASOS_CHOFER, true)) {
            throw new ReglaNegocio('Estado no válido para el chofer.');
        }
        // Un reenvío de una acción ya aplicada responde 200 siempre, aunque su hora ya no pasara las validaciones.
        if ($this->accionYaAplicada($idAccion, $viaje, $chofer)) {
            return $viaje->refresh()->load(['chofer', 'vehiculo', 'solicitante']);
        }
        if ($momento) {
            if ($momento->gt(now()->addMinutes(self::TOLERANCIA_MOMENTO_MIN))) {
                throw new ReglaNegocio('La hora de la acción está en el futuro. Revisá la hora del celular.');
            }
            if ($momento->lt(now()->subHours(self::ANTIGUEDAD_MAXIMA_MOMENTO_H))) {
                throw new ReglaNegocio('La acción tiene más de 24 horas; ya no se puede registrar.');
            }
            $momento = $momento->copy()->min(now());
        }

        try {
            $this->aplicarAvance($viaje, $chofer, $hacia, $momento, $idAccion);
        } catch (UniqueConstraintViolationException) {
            // El mismo id_accion llegó a la vez para otro viaje (cada pedido bloqueó su viaje): vale el primero.
            if (! $this->accionYaAplicada($idAccion, $viaje, $chofer)) {
                throw new ReglaNegocio(self::ACCION_DE_OTRO_VIAJE);
            }
        }

        return $viaje->refresh()->load(['chofer', 'vehiculo', 'solicitante']);
    }

    /** ¿La acción ya se aplicó en este viaje? Si el id_accion es de otro viaje o de otro chofer, 422. */
    private function accionYaAplicada(?string $idAccion, Viaje $viaje, Usuario $chofer): bool
    {
        $previa = $idAccion !== null ? AccionViaje::where('id_accion', $idAccion)->first() : null;
        if (! $previa) {
            return false;
        }
        if ($previa->viaje_id !== $viaje->id || $previa->chofer_id !== $chofer->id) {
            throw new ReglaNegocio(self::ACCION_DE_OTRO_VIAJE);
        }

        return true;
    }

    private function aplicarAvance(Viaje $viaje, Usuario $chofer, EstadoViaje $hacia, ?Carbon $momento, ?string $idAccion): void
    {
        DB::transaction(function () use ($viaje, $chofer, $hacia, $momento, $idAccion) {
            // Mismo primer lock que la máquina de estados (el viaje): dos reenvíos de la misma acción se ordenan acá.
            $viaje->setRawAttributes(Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);

            if ($this->accionYaAplicada($idAccion, $viaje, $chofer)) {
                return; // un reenvío concurrente ya la aplicó: se devuelve el viaje como está
            }

            $this->validarVigencia($viaje, $chofer, $hacia, $idAccion !== null);
            if ($momento && $viaje->estado !== $hacia) {
                $momento = $this->momentoDesdePasoAnterior($viaje, $hacia, $momento);
            }

            if ($hacia === EstadoViaje::EnCamino && $viaje->tipo->esAgendado()) {
                $this->salirHaciaReserva($viaje, $chofer, $momento);
            } else {
                $this->maquina->transicionar($viaje, $hacia, momento: $momento);
            }

            if ($idAccion !== null || $momento !== null) {
                AccionViaje::create([
                    'id_accion' => $idAccion,
                    'viaje_id' => $viaje->id,
                    'chofer_id' => $chofer->id,
                    'estado' => $hacia,
                    'momento' => $momento ?? now(),
                    'aplicada_en' => now(),
                ]);
            }
        }, attempts: 3);
    }

    /**
     * El viaje sigue siendo del chofer. Una acción con `id_accion` puede llegar tarde (la app la guardó sin señal):
     * si el viaje cambió mientras tanto se responde un conflicto, sin cambiar nada, y la app descarta sus pendientes.
     */
    private function validarVigencia(Viaje $viaje, Usuario $chofer, EstadoViaje $hacia, bool $diferida): void
    {
        if ($diferida && $viaje->estado === EstadoViaje::Cancelado) {
            throw new ConflictoViaje('El viaje fue cancelado mientras estabas sin señal.');
        }
        if ($viaje->chofer_id !== $chofer->id) {
            throw $diferida
                ? new ConflictoViaje('El viaje fue reasignado a otro chofer mientras estabas sin señal.')
                : new AccionNoPermitida('Este viaje no es tuyo.');
        }
    }

    /**
     * La acción no puede ser anterior al paso previo del viaje (en_camino no tiene marca propia: vale aceptado_en).
     * Dentro de la tolerancia (la hora del celular puede estar un poco atrasada) se toma la del paso previo.
     */
    private function momentoDesdePasoAnterior(Viaje $viaje, EstadoViaje $hacia, Carbon $momento): Carbon
    {
        $previo = match ($hacia) {
            EstadoViaje::EnCamino, EstadoViaje::Llego => $viaje->aceptado_en,
            EstadoViaje::EnCurso => $viaje->llego_en,
            EstadoViaje::Finalizado => $viaje->iniciado_en,
            default => null,
        };
        if (! $previo || $momento->gte($previo)) {
            return $momento;
        }
        if ($momento->lt($previo->copy()->subMinutes(self::TOLERANCIA_MOMENTO_MIN))) {
            throw new ReglaNegocio('La hora de la acción es anterior al paso anterior del viaje.');
        }

        return $previo->copy();
    }

    /**
     * Spec 5.4 paso 7: la reserva arranca como un viaje normal, pero no antes de tiempo, sin turno ni con otro viaje.
     * Un viaje largo, igual, pero con el vehículo que le asignó el encargado.
     */
    private function salirHaciaReserva(Viaje $viaje, Usuario $chofer, ?Carbon $momento = null): void
    {
        DB::transaction(function () use ($viaje, $chofer, $momento) {
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

            $largo = $viaje->tipo === TipoViaje::Largo;
            [$esta, $la] = $largo ? ['este viaje largo', 'el viaje largo'] : ['esta reserva', 'la reserva'];
            $desde = $viaje->programado_para->copy()->subMinutes($this->parametros->entero('bloqueo_antes_reserva_min'));
            if (($momento ?? now())->lt($desde)) {
                throw new ReglaNegocio("Podés salir hacia $esta a partir de las ".HoraLocal::formatear($desde, 'H:i').'.');
            }

            $turno = $chofer->turnoAbierto()->first()
                ?? throw new ReglaNegocio("Iniciá tu turno para comenzar $la.");

            if (Viaje::activosDeChofer($chofer->id)->whereKeyNot($viaje->id)->exists()) {
                throw new ReglaNegocio("Terminá tu viaje actual antes de comenzar $la.");
            }

            $vehiculoId = $turno->vehiculo_id;
            if ($largo) {
                $vehiculoId = $viaje->vehiculo_id;
                $this->usarVehiculoDelViajeLargo($turno, $vehiculoId);
            }

            $this->maquina->transicionar($viaje, EstadoViaje::EnCamino, ['vehiculo_id' => $vehiculoId], $momento);
        }, attempts: 3);
    }

    /**
     * Al salir en un viaje largo, el turno del chofer pasa al vehículo del viaje (así el vehículo no queda en dos
     * lugares). Mismas reglas y orden de bloqueo que ServicioTurnos::cambiarVehiculo: el chofer ya está bloqueado,
     * después el vehículo.
     */
    private function usarVehiculoDelViajeLargo(Turno $turno, int $vehiculoId): void
    {
        if ((int) $turno->vehiculo_id === $vehiculoId) {
            return;
        }

        $vehiculo = Vehiculo::whereKey($vehiculoId)->lockForUpdate()->first();
        if (! $vehiculo?->activo) {
            throw new ReglaNegocio('El vehículo del viaje no está activo. Avisale al encargado.');
        }

        // Lectura actual (con lock): el snapshot de la transacción puede ser anterior a un turno que otro chofer
        // abrió con este vehículo mientras esperábamos su lock (ver DisponibilidadReservas::estaDisponible).
        $otro = Turno::where('vehiculo_id', $vehiculoId)->whereNull('fin')->whereKeyNot($turno->id)
            ->lockForUpdate()->with('chofer')->first();
        if ($otro) {
            throw new ReglaNegocio("El vehículo del viaje está en uso por {$otro->chofer->nombre}. Avisale al encargado.");
        }

        $turno->update(['vehiculo_id' => $vehiculoId]);
    }

    public function cancelarPorSolicitante(Viaje $viaje, Usuario $solicitante, ?string $motivo): Viaje
    {
        if ($viaje->solicitante_id !== $solicitante->id) {
            throw new AccionNoPermitida('Este viaje no es tuyo.');
        }
        if ($viaje->tipo === TipoViaje::Largo) {
            throw new AccionNoPermitida(self::LARGO_SOLO_ENCARGADO);
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
        // Un viaje largo cambia de chofer conservando su vehículo, con las validaciones de los viajes largos.
        if ($viaje->tipo === TipoViaje::Largo) {
            if ($soloSinChofer) {
                throw new ReglaNegocio('El viaje ya tiene chofer o terminó; no se puede asignar.');
            }

            return $this->largos->reasignar($viaje, $chofer);
        }

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
        return in_array($viaje->estado, $viaje->tipo->esAgendado()
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
            if ($viaje->tipo === TipoViaje::Largo) {
                throw new AccionNoPermitida(self::LARGO_SOLO_ENCARGADO);
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
