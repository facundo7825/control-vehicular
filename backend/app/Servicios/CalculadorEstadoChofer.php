<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Collection;

/** El estado del chofer se calcula siempre; nunca se guarda (spec 4.1). */
class CalculadorEstadoChofer
{
    public function __construct(private Parametros $parametros) {}

    public function estado(Usuario $chofer): EstadoChofer
    {
        if (! $chofer->turnoAbierto()->exists()) {
            return EstadoChofer::FueraDeTurno;
        }

        $ubicacion = $chofer->ubicacion()->first();
        $limiteSenal = now()->subMinutes($this->parametros->entero('sin_senal_min'));
        if (! $ubicacion || $ubicacion->actualizado_en->lt($limiteSenal)) {
            return EstadoChofer::SinSenal;
        }

        if (Viaje::activosDeChofer($chofer->id)->exists()) {
            return EstadoChofer::EnViaje;
        }

        if ($this->tieneReservaProxima($chofer->id)) {
            return EstadoChofer::ReservadoPronto;
        }

        return EstadoChofer::Libre;
    }

    /** @return Collection<int, array{chofer: Usuario, estado: EstadoChofer}> */
    public function choferesEnTurno(): Collection
    {
        return Usuario::where('rol', RolUsuario::Chofer)
            ->whereHas('turnoAbierto')
            ->with(['ubicacion', 'turnoAbierto.vehiculo'])
            ->orderBy('id')
            ->get()
            ->map(fn (Usuario $c) => ['chofer' => $c, 'estado' => $this->estado($c)]);
    }

    /** @return Collection<int, Usuario> */
    public function libres(): Collection
    {
        return $this->choferesEnTurno()
            ->filter(fn (array $f) => $f['estado'] === EstadoChofer::Libre)
            ->map(fn (array $f) => $f['chofer'])
            ->values();
    }

    private function tieneReservaProxima(int $choferId): bool
    {
        return Viaje::where('chofer_id', $choferId)
            ->where('tipo', TipoViaje::Reserva)
            ->where('estado', EstadoViaje::Aceptado)
            ->whereBetween('programado_para', [
                now(), now()->addMinutes($this->parametros->entero('bloqueo_antes_reserva_min')),
            ])
            ->exists();
    }
}
