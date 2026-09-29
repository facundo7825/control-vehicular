<?php

namespace App\Servicios;

use App\Enums\OrigenTurno;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioTurnos
{
    public function iniciar(Usuario $chofer, int $vehiculoId, OrigenTurno $origen = OrigenTurno::Manual): Turno
    {
        if (! $chofer->esChofer()) {
            throw new AccionNoPermitida('Solo los choferes pueden iniciar turno.');
        }

        return DB::transaction(function () use ($chofer, $vehiculoId, $origen) {
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

            return Turno::create([
                'chofer_id' => $chofer->id,
                'vehiculo_id' => $vehiculo->id,
                'inicio' => now(),
                'origen' => $origen,
            ]);
        });
    }

    public function finalizar(Usuario $chofer): Turno
    {
        $turno = $chofer->turnoAbierto()->first()
            ?? throw new ReglaNegocio('No tenés un turno abierto.');

        if (Viaje::activosDeChofer($chofer->id)->exists()) {
            throw new ReglaNegocio('Finalizá el viaje en curso antes de cerrar el turno.');
        }

        $turno->update(['fin' => now()]);
        // Privacidad: fuera de turno no se conserva la ubicación.
        UbicacionChofer::where('chofer_id', $chofer->id)->delete();

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
