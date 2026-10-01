<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Models\Viaje;
use Illuminate\Support\Carbon;

/**
 * Números del día y series de los gráficos del tablero.
 * La base está en UTC: se acota por los límites del día local convertidos a UTC
 * y se agrupa por día u hora local en PHP (sin funciones de fecha propias de un motor).
 */
class EstadisticasPanel
{
    public function __construct(private CalculadorEstadoChofer $estados) {}

    /** @return array{total: int, finalizados: int, cancelados: int} viajes pedidos hoy (día local) */
    public function viajesHoy(): array
    {
        [$desde, $hasta] = $this->diaLocal();
        $estados = Viaje::where('created_at', '>=', $desde)
            ->where('created_at', '<', $hasta)
            ->pluck('estado');

        return [
            'total' => $estados->count(),
            'finalizados' => $estados->filter(fn (EstadoViaje $e) => $e === EstadoViaje::Finalizado)->count(),
            'cancelados' => $estados->filter(fn (EstadoViaje $e) => $e === EstadoViaje::Cancelado)->count(),
        ];
    }

    /** Minutos promedio del pedido a "llegó" en los viajes inmediatos que llegaron hoy (día local); null sin datos. */
    public function esperaPromedioHoy(): ?float
    {
        [$desde, $hasta] = $this->diaLocal();
        $esperas = Viaje::where('tipo', TipoViaje::Inmediato)
            ->where('llego_en', '>=', $desde)
            ->where('llego_en', '<', $hasta)
            ->get(['created_at', 'llego_en'])
            ->map(fn (Viaje $v) => $v->created_at->diffInSeconds($v->llego_en) / 60);

        return $esperas->isEmpty() ? null : round($esperas->avg(), 1);
    }

    /** @return array{total: int, libres: int, en_viaje: int} */
    public function choferesEnTurno(): array
    {
        $estados = $this->estados->choferesEnTurno()->pluck('estado');

        return [
            'total' => $estados->count(),
            'libres' => $estados->filter(fn (EstadoChofer $e) => $e === EstadoChofer::Libre)->count(),
            'en_viaje' => $estados->filter(fn (EstadoChofer $e) => $e === EstadoChofer::EnViaje)->count(),
        ];
    }

    /**
     * Viajes finalizados, cancelados y sin chofer por día local, de los últimos $dias días (hoy incluido).
     * Para "sin chofer" no hay una columna propia: se usa updated_at, que es el momento de esa transición
     * (igual que ResumenPanel::viajesSinChoferRecientes).
     *
     * @return array{etiquetas: list<string>, finalizados: list<int>, cancelados: list<int>, sin_chofer: list<int>}
     */
    public function viajesPorDia(int $dias = 14): array
    {
        $hoy = Carbon::now($this->zona())->startOfDay();
        $primero = $hoy->copy()->subDays($dias - 1);
        $desde = $primero->copy()->utc();
        $hasta = $hoy->copy()->addDay()->utc();

        $dias = collect(range(0, $dias - 1))->map(fn (int $i) => $primero->copy()->addDays($i));
        $vacio = $dias->mapWithKeys(fn (Carbon $d) => [$d->format('Y-m-d') => 0])->all();

        $contar = function (EstadoViaje $estado, string $columna) use ($desde, $hasta, $vacio): array {
            $conteo = $vacio;
            Viaje::where('estado', $estado)
                ->where($columna, '>=', $desde)
                ->where($columna, '<', $hasta)
                ->pluck($columna)
                ->each(function ($momento) use (&$conteo) {
                    $conteo[Carbon::parse($momento)->setTimezone($this->zona())->format('Y-m-d')]++;
                });

            return array_values($conteo);
        };

        return [
            'etiquetas' => $dias->map(fn (Carbon $d) => $d->format('d/m'))->all(),
            'finalizados' => $contar(EstadoViaje::Finalizado, 'finalizado_en'),
            'cancelados' => $contar(EstadoViaje::Cancelado, 'cancelado_en'),
            'sin_chofer' => $contar(EstadoViaje::SinChofer, 'updated_at'),
        ];
    }

    /** @return list<int> pedidos por hora local (0 a 23) de los últimos $dias días (hoy incluido) */
    public function pedidosPorHora(int $dias = 30): array
    {
        $desde = Carbon::now($this->zona())->startOfDay()->subDays($dias - 1)->utc();
        $conteo = array_fill(0, 24, 0);

        Viaje::where('created_at', '>=', $desde)
            ->pluck('created_at')
            ->each(function (Carbon $momento) use (&$conteo) {
                $conteo[(int) $momento->copy()->setTimezone($this->zona())->format('G')]++;
            });

        return $conteo;
    }

    /** @return array{0: Carbon, 1: Carbon} inicio y fin (exclusivo) del día local de hoy, en UTC */
    private function diaLocal(): array
    {
        $inicio = Carbon::now($this->zona())->startOfDay();

        return [$inicio->copy()->utc(), $inicio->copy()->addDay()->utc()];
    }

    private function zona(): string
    {
        return config('vehiculos.zona_horaria');
    }
}
