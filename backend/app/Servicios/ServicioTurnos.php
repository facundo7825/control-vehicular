<?php

namespace App\Servicios;

use App\Enums\EstadoViaje;
use App\Enums\OrigenTurno;
use App\Enums\TipoViaje;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Models\Alerta;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\Support\HoraLocal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioTurnos
{
    public function __construct(
        private AvisoEstadoChofer $aviso,
        private Parametros $parametros,
    ) {}

    public function iniciar(Usuario $chofer, int $vehiculoId, OrigenTurno $origen = OrigenTurno::Manual): Turno
    {
        if (! $chofer->esChofer()) {
            throw new AccionNoPermitida('Solo los choferes pueden iniciar turno.');
        }

        $turno = DB::transaction(function () use ($chofer, $vehiculoId, $origen) {
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();
            $vehiculo = Vehiculo::whereKey($vehiculoId)->lockForUpdate()->first();

            if (! $vehiculo || ! $vehiculo->activo) {
                throw new ReglaNegocio('El vehículo no existe o no está activo.');
            }
            if (Turno::where('chofer_id', $chofer->id)->whereNull('fin')->exists()) {
                throw new ReglaNegocio('Ya tenés un turno abierto.');
            }
            if (Turno::where('vehiculo_id', $vehiculo->id)->whereNull('fin')->exists()) {
                throw new ReglaNegocio('El vehículo está en uso por otro chofer.');
            }
            $this->rechazarSiEstaEnViajeLargo($vehiculo->id, $chofer->id);

            // Con el turno abierto (manual o por fichaje) deja de valer el aviso de "fichó sin vehículo".
            Alerta::pendientes()->where('tipo', Alerta::ASISTENCIA_SIN_VEHICULO)->where('chofer_id', $chofer->id)
                ->update(['resuelta_en' => now()]);

            return Turno::create([
                'chofer_id' => $chofer->id,
                'vehiculo_id' => $vehiculo->id,
                'inicio' => now(),
                'origen' => $origen,
            ]);
        }, attempts: 3);

        $this->aviso->publicarSiCambio($chofer);

        return $turno;
    }

    /**
     * Cambia el vehículo del turno abierto (el turno abierto por fichaje usa el habitual y ese día maneja otro).
     * Mismos locks y orden que iniciar: primero el chofer (que también toma Asignador) y después el vehículo.
     * Con un viaje activo no se permite: el viaje ya quedó con el vehículo del turno.
     */
    public function cambiarVehiculo(Usuario $chofer, int $vehiculoId): Turno
    {
        return DB::transaction(function () use ($chofer, $vehiculoId) {
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();
            $vehiculo = Vehiculo::whereKey($vehiculoId)->lockForUpdate()->first();

            $turno = $chofer->turnoAbierto()->first();
            if (! $turno) {
                throw new ReglaNegocio('No tenés un turno abierto.');
            }
            if ((int) $turno->vehiculo_id === $vehiculoId) {
                return $turno;
            }
            if (! $vehiculo || ! $vehiculo->activo) {
                throw new ReglaNegocio('El vehículo no existe o no está activo.');
            }
            if (Viaje::activosDeChofer($chofer->id)->exists()) {
                throw new ReglaNegocio('Terminá el viaje en curso antes de cambiar de vehículo.');
            }
            if (Turno::where('vehiculo_id', $vehiculo->id)->whereNull('fin')->exists()) {
                throw new ReglaNegocio('El vehículo está en uso por otro chofer.');
            }
            $this->rechazarSiEstaEnViajeLargo($vehiculo->id, $chofer->id);

            $turno->update(['vehiculo_id' => $vehiculo->id]);

            return $turno;
        }, attempts: 3);
    }

    public function finalizar(Usuario $chofer): Turno
    {
        return $this->cerrar($chofer, soloPendiente: false);
    }

    /**
     * Cierra el turno que quedó con cierre pendiente (fichó la salida con un viaje activo) si ya no tiene
     * viajes activos. Devuelve null, sin lanzar, si no corresponde: no hay turno, no está pendiente (una
     * entrada posterior lo anuló) o todavía tiene un viaje activo. Se decide con la fila del chofer bloqueada.
     */
    public function finalizarPendiente(Usuario $chofer): ?Turno
    {
        return $this->cerrar($chofer, soloPendiente: true);
    }

    private function cerrar(Usuario $chofer, bool $soloPendiente): ?Turno
    {
        $turno = DB::transaction(function () use ($chofer, $soloPendiente) {
            // Mismo bloqueo que toma Asignador::asignar: no se puede cerrar el turno mientras se le asigna un viaje.
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();

            $turno = $chofer->turnoAbierto()->first();
            $conViaje = Viaje::activosDeChofer($chofer->id)->exists();

            if ($soloPendiente && (! $turno?->cierre_pendiente_en || $conViaje)) {
                return null;
            }
            if (! $turno) {
                throw new ReglaNegocio('No tenés un turno abierto.');
            }
            if ($conViaje) {
                throw new ReglaNegocio('Finalizá el viaje en curso antes de cerrar el turno.');
            }

            $turno->update(['fin' => now()]);
            // Privacidad: fuera de turno no se conserva la ubicación.
            UbicacionChofer::where('chofer_id', $chofer->id)->delete();

            return $turno;
        }, attempts: 3);

        if ($turno) {
            $this->aviso->publicarSiCambio($chofer);
        }

        return $turno;
    }

    /** @return Collection<int, Vehiculo> */
    public function vehiculosDisponibles(): Collection
    {
        return Vehiculo::where('activo', true)
            ->whereNotIn('id', Turno::whereNull('fin')->select('vehiculo_id'))
            ->orderBy('patente')
            ->get();
    }

    /**
     * Un vehículo que salió en un viaje largo de otro chofer (en camino, llegó o en curso) no se usa en otro turno
     * hasta que vuelve; tampoco uno reservado para un viaje largo de otro chofer que sale pronto (dentro del bloqueo
     * previo a las reservas, o con la salida ya pasada sin arrancar). El chofer de ese viaje sí puede usarlo.
     *
     * Lectura actual (FOR UPDATE, solo filas de ese vehículo): el vehículo ya está bloqueado, y quien le asigna un
     * viaje largo lo bloquea también; tras esperar su lock, el snapshot de la transacción puede ser anterior.
     */
    private function rechazarSiEstaEnViajeLargo(int $vehiculoId, int $choferId): void
    {
        $largos = Viaje::where('vehiculo_id', $vehiculoId)
            ->where('tipo', TipoViaje::Largo)
            ->whereIn('estado', EstadoViaje::conChofer())
            ->where('chofer_id', '!=', $choferId)
            ->forceIndex('viajes_vehiculo_id_estado_index')
            ->lockForUpdate()
            ->get();

        if ($largos->contains(fn (Viaje $v) => $v->estado !== EstadoViaje::Aceptado)) {
            throw new ReglaNegocio('El vehículo está en un viaje largo.');
        }

        $limite = now()->addMinutes($this->parametros->entero('bloqueo_antes_reserva_min'));
        $pronto = $largos->filter(fn (Viaje $v) => $v->programado_para->lte($limite))->sortBy('programado_para')->first();
        if ($pronto) {
            throw new ReglaNegocio('El vehículo está reservado para un viaje largo que sale a las '
                .HoraLocal::formatear($pronto->programado_para, 'H:i').'.');
        }
    }
}
