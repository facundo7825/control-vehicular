<?php

namespace App\Servicios;

use App\Enums\OrigenTurno;
use App\Excepciones\ReglaNegocio;
use App\Jobs\AvisarTurno;
use App\Models\Alerta;
use App\Models\EventoAsistencia as Evento;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fichajes del control de asistencia (plan 2026-10-05): la entrada abre el turno del chofer con su vehículo
 * habitual y la salida lo cierra (o lo deja con cierre pendiente si tiene un viaje activo). Cada evento queda
 * registrado en eventos_asistencia con su resultado; los turnos pasan siempre por ServicioTurnos y sus locks.
 *
 * Cada evento se procesa en una sola transacción con la fila del usuario bloqueada: los eventos de una
 * misma persona van de a uno (idempotencia y orden incluidos), y el registro, el turno y la alerta se
 * guardan juntos o no se guarda nada. Los push van por la cola después del commit: un reintento tras una falla
 * no los repite, y uno ya registrado devuelve el resultado guardado sin efectos.
 */
class ServicioAsistencia
{
    public function __construct(private ServicioTurnos $turnos) {}

    /** @return array{resultado: string, motivo: string} */
    public function procesar(string $idExterno, string $tipo, ?Carbon $momento = null, ?string $idEvento = null): array
    {
        $momento ??= now();

        try {
            $evento = DB::transaction(function () use ($idExterno, $tipo, $momento, $idEvento) {
                // Mismo bloqueo que ServicioTurnos (iniciar/finalizar) y Asignador: primero el usuario.
                $usuario = Usuario::where('id_externo', $idExterno)->lockForUpdate()->first();

                if ($idEvento !== null && $previo = Evento::where('id_evento', $idEvento)->first()) {
                    return $previo;
                }

                [$resultado, $motivo] = $this->resolver($usuario, $idExterno, $tipo, $momento);

                return Evento::create([
                    'id_evento' => $idEvento,
                    'usuario_id' => $usuario?->id,
                    'id_externo' => $idExterno,
                    'tipo' => $tipo,
                    'momento' => $momento,
                    'resultado' => $resultado,
                    'motivo' => $motivo,
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            // El mismo id_evento de alguien sin usuario llegó dos veces a la vez: vale el primero.
            $evento = Evento::where('id_evento', $idEvento)->firstOrFail();
        }

        return ['resultado' => $evento->resultado, 'motivo' => $evento->motivo];
    }

    /**
     * Cierra el turno con cierre pendiente si el chofer ya no tiene viajes activos y le avisa.
     * Lo llama CerrarTurnoPendiente cuando termina un viaje (y la salida, por si el viaje terminó entretanto).
     * Con $finViaje (el viaje finalizó a esa hora), el turno no termina antes de la salida fichada.
     */
    public function cerrarPendiente(Usuario $chofer, ?Carbon $finViaje = null): bool
    {
        if (! $this->turnos->finalizarPendiente($chofer, $finViaje)) {
            return false;
        }

        $this->avisarCierre($chofer);

        return true;
    }

    /** @return array{0: string, 1: string} */
    private function resolver(?Usuario $usuario, string $idExterno, string $tipo, Carbon $momento): array
    {
        $ultimo = Evento::where('id_externo', $idExterno)->max('momento');
        if ($ultimo !== null && $momento->lt(Carbon::parse($ultimo))) {
            return [Evento::IGNORADO, 'Evento fuera de orden: es anterior al último fichaje procesado.'];
        }

        if (! $usuario) {
            return [Evento::IGNORADO, 'No hay ningún usuario con ese id_externo.'];
        }
        if (! $usuario->activo) {
            return [Evento::IGNORADO, 'El usuario está deshabilitado.'];
        }
        if (! $usuario->esChofer()) {
            return [Evento::IGNORADO, 'El usuario no es chofer.'];
        }

        return $tipo === Evento::ENTRADA ? $this->entrada($usuario, $momento) : $this->salida($usuario, $momento);
    }

    /** @return array{0: string, 1: string} */
    private function entrada(Usuario $chofer, Carbon $momento): array
    {
        if ($turno = $chofer->turnoAbierto()->first()) {
            if (! $turno->cierre_pendiente_en) {
                return [Evento::IGNORADO, 'Ya tenía un turno abierto.'];
            }
            // Una entrada posterior anula el cierre pendiente.
            $turno->update(['cierre_pendiente_en' => null]);
            $this->avisar($chofer, 'Seguís de turno', 'Fichaste la entrada: se anuló el cierre del turno.', 'cierre_cancelado');

            return [Evento::IGNORADO, 'Ya tenía un turno abierto; se anuló el cierre pendiente.'];
        }

        // Un fichaje atrasado no abre un turno nuevo: si es de antes del último cierre ya se usó (o era de
        // un turno anterior), y si es muy viejo el chofer probablemente ya no esté (ponerse al día tras un corte).
        $ultimoCierre = Turno::where('chofer_id', $chofer->id)->whereNotNull('fin')->max('fin');
        if ($ultimoCierre !== null && $momento->lt(Carbon::parse($ultimoCierre))) {
            return [Evento::IGNORADO, 'La entrada es anterior al cierre del último turno.'];
        }
        if ($momento->lt(now()->subHours((int) config('vehiculos.asistencia.entrada_max_horas')))) {
            return [Evento::IGNORADO, 'Fichaje de entrada demasiado antiguo para abrir el turno.'];
        }

        if (! $chofer->vehiculo_habitual_id) {
            return $this->sinVehiculo($chofer, 'No tiene vehículo habitual asignado.');
        }

        try {
            $this->turnos->iniciar($chofer, $chofer->vehiculo_habitual_id, OrigenTurno::Asistencia);
        } catch (ReglaNegocio $e) {
            $vehiculo = Vehiculo::find($chofer->vehiculo_habitual_id);

            // En uso por otro chofer, en un viaje largo o reservado para uno que sale pronto.
            return $this->sinVehiculo($chofer, $vehiculo?->activo
                ? 'El vehículo habitual no está disponible: '.$e->getMessage()
                : 'El vehículo habitual no existe o no está activo.');
        }

        $this->avisar($chofer, 'Tu turno empezó', 'Abrí la app para compartir tu ubicación', 'abierto');

        return [Evento::ABIERTO, 'Turno abierto con el vehículo habitual.'];
    }

    /** @return array{0: string, 1: string} */
    private function sinVehiculo(Usuario $chofer, string $motivo): array
    {
        // Una sola alerta pendiente por chofer: otra entrada sin vehículo no la repite.
        if (! $this->alertasSinVehiculo($chofer)->exists()) {
            Alerta::create([
                'tipo' => Alerta::ASISTENCIA_SIN_VEHICULO,
                'chofer_id' => $chofer->id,
                'mensaje' => "{$chofer->nombre} fichó la entrada pero no tiene vehículo habitual disponible",
            ]);
        }
        $this->avisar($chofer, 'Fichaste la entrada', 'Abrí la app y elegí el vehículo para empezar el turno', 'sin_vehiculo');

        return [Evento::SIN_VEHICULO, $motivo];
    }

    /** @return array{0: string, 1: string} */
    private function salida(Usuario $chofer, Carbon $momento): array
    {
        // Con la salida deja de valer el aviso de "fichó sin vehículo" (se fue sin iniciar turno).
        $this->alertasSinVehiculo($chofer)->update(['resuelta_en' => now()]);

        $turno = $chofer->turnoAbierto()->first();
        if (! $turno) {
            return [Evento::IGNORADO, 'No tenía un turno abierto.'];
        }
        if ($momento->lt($this->comienzo($turno))) {
            return [Evento::IGNORADO, 'La salida es anterior al inicio del turno abierto.'];
        }

        try {
            $this->turnos->finalizar($chofer);
            $this->avisarCierre($chofer);

            return [Evento::CERRADO, 'Turno cerrado.'];
        } catch (ReglaNegocio) {
            // Tiene un viaje activo: el turno queda con cierre pendiente.
        }

        $turno->update(['cierre_pendiente_en' => now()]);
        $this->avisar($chofer, 'Fichaste la salida', 'Tu turno se cierra al terminar el viaje.', 'cierre_pendiente');
        // Si el viaje terminó mientras tanto, su aviso no vio la marca (todavía sin commit): se reintenta
        // el cierre después del commit, con la fila del chofer bloqueada y datos frescos. El fichaje ya quedó
        // registrado: si el reintento falla se reporta y el turno sigue con cierre pendiente (lo cierra el
        // fin del viaje), sin convertirse en un error del pedido.
        DB::afterCommit(function () use ($chofer) {
            try {
                $this->cerrarPendiente($chofer);
            } catch (Throwable $error) {
                report($error);
            }
        });

        return [Evento::CIERRE_PENDIENTE, 'Tiene un viaje activo: el turno se cierra cuando lo termine.'];
    }

    /**
     * Desde cuándo vale una salida para el turno abierto: su inicio o, si lo abrió un fichaje, el momento de esa
     * entrada (puede ser anterior al inicio, p. ej. entrada y salida atrasadas que llegan juntas en un lote).
     */
    private function comienzo(Turno $turno): Carbon
    {
        if ($turno->origen === OrigenTurno::Asistencia) {
            $entrada = Evento::where('usuario_id', $turno->chofer_id)->where('resultado', Evento::ABIERTO)
                ->where('momento', '<=', $turno->inicio)->max('momento');
            if ($entrada !== null) {
                return Carbon::parse($entrada);
            }
        }

        return $turno->inicio;
    }

    private function alertasSinVehiculo(Usuario $chofer): Builder
    {
        return Alerta::pendientes()->where('tipo', Alerta::ASISTENCIA_SIN_VEHICULO)->where('chofer_id', $chofer->id);
    }

    private function avisarCierre(Usuario $chofer): void
    {
        $this->avisar($chofer, 'Tu turno terminó', 'Se registró tu salida.', 'cerrado');
    }

    /** El push va por la cola después del commit: si la transacción se revierte, no se avisa nada. */
    private function avisar(Usuario $chofer, string $titulo, string $cuerpo, string $estado): void
    {
        AvisarTurno::dispatch($chofer->id, $titulo, $cuerpo, $estado);
    }
}
