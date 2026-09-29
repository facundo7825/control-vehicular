<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Models\Alerta;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Collection;

/** Números del tablero del panel (spec 8.6). */
class ResumenPanel
{
    public function __construct(private CalculadorEstadoChofer $estados) {}

    public function alertasPendientes(): int
    {
        return Alerta::pendientes()->count();
    }

    /** Viajes que quedaron sin chofer en las últimas 24 h (updated_at es el momento de esa transición). */
    public function viajesSinChoferRecientes(): int
    {
        return Viaje::where('estado', EstadoViaje::SinChofer)
            ->where('updated_at', '>=', now()->subDay())
            ->count();
    }

    /** @return Collection<int, Usuario> choferes "sin señal" (spec 4.1) que tienen un viaje activo */
    public function choferesSinSenalEnViaje(): Collection
    {
        return $this->estados->choferesEnTurno()
            ->filter(fn (array $f) => $f['estado'] === EstadoChofer::SinSenal
                && Viaje::activosDeChofer($f['chofer']->id)->exists())
            ->map(fn (array $f) => $f['chofer'])
            ->values();
    }
}
