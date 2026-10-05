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
 */
class ServicioAsistencia
{
    public function __construct(private ServicioTurnos $turnos, private Notificador $push) {}

    /** @return array{resultado: string, motivo: string} */
    public function procesar(string $idExterno, string $tipo, ?Carbon $momento = null, ?string $idEvento = null): array
    {
        if ($idEvento !== null && $previo = Evento::where('id_evento', $idEvento)->first()) {
            return $this->respuesta($previo);
        }

        $momento ??= now();
        $usuario = Usuario::where('id_externo', $idExterno)->first();

        [$resultado, $motivo] = $this->resolver($usuario, $idExterno, $tipo, $momento);

        try {
            $evento = Evento::create([
                'id_evento' => $idEvento,
                'usuario_id' => $usuario?->id,
                'id_externo' => $idExterno,
                'tipo' => $tipo,
                'momento' => $momento,
                'resultado' => $resultado,
                'motivo' => $motivo,
            ]);
        } catch (UniqueConstraintViolationException) {
            // El mismo id_evento llegó dos veces a la vez: vale el que se registró primero.
            $evento = Evento::where('id_evento', $idEvento)->firstOrFail();
        }

        return $this->respuesta($evento);
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
        // Una entrada posterior anula el cierre pendiente (con la fila bloqueada, como el cierre).
        $anulado = DB::transaction(function () use ($chofer) {
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();
            $turno = $chofer->turnoAbierto()->first();
            if ($turno?->cierre_pendiente_en) {
                $turno->update(['cierre_pendiente_en' => null]);
            }

            return $turno ? (bool) $turno->wasChanged('cierre_pendiente_en') : null;
        });

        if ($anulado !== null) {
            return [Evento::IGNORADO, $anulado
                ? 'Ya tenía un turno abierto; se anuló el cierre pendiente.'
                : 'Ya tenía un turno abierto.'];
        }

        if (! $chofer->vehiculo_habitual_id) {
            return $this->sinVehiculo($chofer, 'No tiene vehículo habitual asignado.');
        }

        try {
            $this->turnos->iniciar($chofer, $chofer->vehiculo_habitual_id, OrigenTurno::Asistencia);
        } catch (ReglaNegocio) {
            // Un inicio manual simultáneo le ganó (los locks de iniciar lo serializan).
            if ($chofer->turnoAbierto()->exists()) {
                return [Evento::IGNORADO, 'Ya tenía un turno abierto.'];
            }
            $vehiculo = Vehiculo::find($chofer->vehiculo_habitual_id);

            return $this->sinVehiculo($chofer, $vehiculo?->activo
                ? 'El vehículo habitual está en uso por otro chofer.'
                : 'El vehículo habitual no existe o no está activo.');
        }

        $this->push->enviar($chofer, 'Tu turno empezó', 'Abrí la app para compartir tu ubicación',
            ['tipo' => 'turno', 'estado' => 'abierto']);

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
        $this->push->enviar($chofer, 'Fichaste la entrada', 'Abrí la app y elegí el vehículo para empezar el turno',
            ['tipo' => 'turno', 'estado' => 'sin_vehiculo']);

        return [Evento::SIN_VEHICULO, $motivo];
    }

    /** @return array{0: string, 1: string} */
    private function salida(Usuario $chofer): array
    {
        if (! $chofer->turnoAbierto()->exists()) {
            return [Evento::IGNORADO, 'No tenía un turno abierto.'];
        }

        try {
            $this->turnos->finalizar($chofer);
            $this->avisarCierre($chofer);

            return [Evento::CERRADO, 'Turno cerrado.'];
        } catch (ReglaNegocio) {
            // Tiene un viaje activo (o el turno se cerró entretanto): se marca el cierre pendiente.
        }

        $marcado = DB::transaction(function () use ($chofer) {
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();

            return (bool) $chofer->turnoAbierto()->first()?->update(['cierre_pendiente_en' => now()]);
        });

        if (! $marcado) {
            return [Evento::IGNORADO, 'No tenía un turno abierto.'];
        }

        // Si el viaje terminó entre el intento de cierre y la marca, su aviso ya no la vio: se cierra acá.
        if ($this->cerrarPendiente($chofer)) {
            return [Evento::CERRADO, 'Turno cerrado.'];
        }

        return [Evento::CIERRE_PENDIENTE, 'Tiene un viaje activo: el turno se cierra cuando lo termine.'];
    }

    private function avisarCierre(Usuario $chofer): void
    {
        $this->push->enviar($chofer, 'Tu turno terminó', 'Se registró tu salida.',
            ['tipo' => 'turno', 'estado' => 'cerrado']);
    }

    /** @return array{resultado: string, motivo: string} */
    private function respuesta(Evento $evento): array
    {
        return ['resultado' => $evento->resultado, 'motivo' => $evento->motivo];
    }
}
