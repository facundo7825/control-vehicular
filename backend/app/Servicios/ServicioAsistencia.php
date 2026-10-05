<?php

namespace App\Servicios;

use App\Enums\OrigenTurno;
use App\Excepciones\ReglaNegocio;
use App\Models\Alerta;
use App\Models\EventoAsistencia as Evento;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Notificaciones\Notificador;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fichajes del control de asistencia (plan 2026-10-05): la entrada abre el turno del chofer con su vehículo
 * habitual y la salida lo cierra (o lo deja con cierre pendiente si tiene un viaje activo). Cada evento queda
 * registrado en eventos_asistencia con su resultado; los turnos pasan siempre por ServicioTurnos y sus locks.
 *
 * Cada evento se procesa en una sola transacción con la fila del usuario bloqueada: los eventos de una
 * misma persona van de a uno (idempotencia y orden incluidos), y el registro, el turno y la alerta se
 * guardan juntos o no se guarda nada. Los push salen después del commit: un reintento tras una falla
 * no los repite, y uno ya registrado devuelve el resultado guardado sin efectos.
 */
class ServicioAsistencia
{
    public function __construct(private ServicioTurnos $turnos, private Notificador $push) {}

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
     */
    public function cerrarPendiente(Usuario $chofer): bool
    {
        if (! $this->turnos->finalizarPendiente($chofer)) {
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

        return $tipo === Evento::ENTRADA ? $this->entrada($usuario) : $this->salida($usuario);
    }

    /** @return array{0: string, 1: string} */
    private function entrada(Usuario $chofer): array
    {
        if ($turno = $chofer->turnoAbierto()->first()) {
            if (! $turno->cierre_pendiente_en) {
                return [Evento::IGNORADO, 'Ya tenía un turno abierto.'];
            }
            // Una entrada posterior anula el cierre pendiente.
            $turno->update(['cierre_pendiente_en' => null]);

            return [Evento::IGNORADO, 'Ya tenía un turno abierto; se anuló el cierre pendiente.'];
        }

        if (! $chofer->vehiculo_habitual_id) {
            return $this->sinVehiculo($chofer, 'No tiene vehículo habitual asignado.');
        }

        try {
            $this->turnos->iniciar($chofer, $chofer->vehiculo_habitual_id, OrigenTurno::Asistencia);
        } catch (ReglaNegocio) {
            $vehiculo = Vehiculo::find($chofer->vehiculo_habitual_id);

            return $this->sinVehiculo($chofer, $vehiculo?->activo
                ? 'El vehículo habitual está en uso por otro chofer.'
                : 'El vehículo habitual no existe o no está activo.');
        }

        $this->avisar($chofer, 'Tu turno empezó', 'Abrí la app para compartir tu ubicación', 'abierto');

        return [Evento::ABIERTO, 'Turno abierto con el vehículo habitual.'];
    }

    /** @return array{0: string, 1: string} */
    private function sinVehiculo(Usuario $chofer, string $motivo): array
    {
        Alerta::create([
            'tipo' => Alerta::ASISTENCIA_SIN_VEHICULO,
            'chofer_id' => $chofer->id,
            'mensaje' => "{$chofer->nombre} fichó la entrada pero no tiene vehículo habitual disponible",
        ]);
        $this->avisar($chofer, 'Fichaste la entrada', 'Abrí la app y elegí el vehículo para empezar el turno', 'sin_vehiculo');

        return [Evento::SIN_VEHICULO, $motivo];
    }

    /** @return array{0: string, 1: string} */
    private function salida(Usuario $chofer): array
    {
        $turno = $chofer->turnoAbierto()->first();
        if (! $turno) {
            return [Evento::IGNORADO, 'No tenía un turno abierto.'];
        }

        try {
            $this->turnos->finalizar($chofer);
            $this->avisarCierre($chofer);

            return [Evento::CERRADO, 'Turno cerrado.'];
        } catch (ReglaNegocio) {
            // Tiene un viaje activo: el turno queda con cierre pendiente.
        }

        $turno->update(['cierre_pendiente_en' => now()]);
        // Si el viaje terminó mientras tanto, su aviso no vio la marca (todavía sin commit): se reintenta
        // el cierre después del commit, con la fila del chofer bloqueada y datos frescos.
        DB::afterCommit(fn () => $this->cerrarPendiente($chofer));

        return [Evento::CIERRE_PENDIENTE, 'Tiene un viaje activo: el turno se cierra cuando lo termine.'];
    }

    private function avisarCierre(Usuario $chofer): void
    {
        $this->avisar($chofer, 'Tu turno terminó', 'Se registró tu salida.', 'cerrado');
    }

    /** El push sale después del commit: si la transacción se revierte, no se avisa nada. */
    private function avisar(Usuario $chofer, string $titulo, string $cuerpo, string $estado): void
    {
        DB::afterCommit(fn () => $this->push->enviar($chofer, $titulo, $cuerpo, ['tipo' => 'turno', 'estado' => $estado]));
    }
}
