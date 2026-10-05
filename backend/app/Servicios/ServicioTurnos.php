<?php

namespace App\Servicios;

use App\Enums\OrigenTurno;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Models\Alerta;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioTurnos
{
    public function __construct(private AvisoEstadoChofer $aviso) {}

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
}
