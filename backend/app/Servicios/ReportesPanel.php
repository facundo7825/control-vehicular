<?php

namespace App\Servicios;

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reportes por chofer y por vehículo de un rango de días locales (desde y hasta incluidos, 'Y-m-d').
 * La base está en UTC: el rango se convierte a límites UTC y se consulta con unas pocas consultas
 * agrupadas (sin una por chofer). Solo agregados: nunca se exponen ubicaciones (spec 10).
 */
class ReportesPanel
{
    public function __construct(private Parametros $parametros) {}

    /** @return array{desde: string, hasta: string} el mes actual en días locales */
    public function rangoPorDefecto(): array
    {
        $hoy = Carbon::now($this->zona());

        return [
            'desde' => $hoy->copy()->startOfMonth()->format('Y-m-d'),
            'hasta' => $hoy->copy()->endOfMonth()->format('Y-m-d'),
        ];
    }

    /** Aviso si el rango empieza antes de lo que se conserva el recorrido (los km de antes no se pueden calcular). */
    public function avisoRetencion(string $desde): ?string
    {
        $dias = $this->parametros->entero('retencion_recorrido_dias');
        [$inicio] = $this->limites($desde, $desde);

        if ($inicio->greaterThanOrEqualTo(now()->subDays($dias))) {
            return null;
        }

        return "El recorrido de los viajes se guarda $dias días: los km cubren solo los últimos $dias días.";
    }

    /**
     * @return list<array{chofer_id: int, chofer: string, finalizados: int, cancelados: int, km: float,
     *                    horas_turno: float, llegada_promedio_min: float|null}>
     */
    public function porChofer(string $desde, string $hasta): array
    {
        [$inicio, $fin] = $this->limites($desde, $hasta);

        $finalizados = $this->finalizados($inicio, $fin)->get(['id', 'chofer_id']);
        $cancelados = Viaje::where('estado', EstadoViaje::Cancelado)
            ->whereNotNull('chofer_id')
            ->where('cancelado_en', '>=', $inicio)
            ->where('cancelado_en', '<', $fin)
            ->pluck('chofer_id')
            ->countBy();
        $kmPorViaje = $this->kmPorViaje($inicio, $fin);
        $horas = $this->horasDeTurno($inicio, $fin, 'chofer_id');
        $llegadas = Viaje::where('tipo', TipoViaje::Inmediato)
            ->whereNotNull('chofer_id')
            ->whereNotNull('aceptado_en')
            ->where('llego_en', '>=', $inicio)
            ->where('llego_en', '<', $fin)
            ->get(['chofer_id', 'aceptado_en', 'llego_en'])
            ->groupBy('chofer_id')
            ->map(fn (Collection $viajes) => round($viajes->avg(
                fn (Viaje $v) => $v->aceptado_en->diffInSeconds($v->llego_en) / 60), 1));

        $ids = $finalizados->pluck('chofer_id')
            ->merge($cancelados->keys())->merge($horas->keys())->merge($llegadas->keys())
            ->filter()->unique();
        $porChofer = $finalizados->groupBy('chofer_id');

        return Usuario::whereIn('id', $ids)->orderBy('nombre')->get(['id', 'nombre'])
            ->map(fn (Usuario $chofer) => [
                'chofer_id' => $chofer->id,
                'chofer' => $chofer->nombre,
                'finalizados' => $porChofer->get($chofer->id, collect())->count(),
                'cancelados' => (int) $cancelados->get($chofer->id, 0),
                'km' => $this->sumarKm($porChofer->get($chofer->id, collect()), $kmPorViaje),
                'horas_turno' => round($horas->get($chofer->id, 0) / 3600, 1),
                'llegada_promedio_min' => $llegadas->get($chofer->id),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{vehiculo_id: int, patente: string, vehiculo: string, finalizados: int, km: float, horas_turno: float}>
     */
    public function porVehiculo(string $desde, string $hasta): array
    {
        [$inicio, $fin] = $this->limites($desde, $hasta);

        $finalizados = $this->finalizados($inicio, $fin)->whereNotNull('vehiculo_id')->get(['id', 'vehiculo_id']);
        $kmPorViaje = $this->kmPorViaje($inicio, $fin);
        $horas = $this->horasDeTurno($inicio, $fin, 'vehiculo_id');

        $ids = $finalizados->pluck('vehiculo_id')->merge($horas->keys())->filter()->unique();
        $porVehiculo = $finalizados->groupBy('vehiculo_id');

        return Vehiculo::whereIn('id', $ids)->orderBy('patente')->get(['id', 'patente', 'marca', 'modelo'])
            ->map(fn (Vehiculo $vehiculo) => [
                'vehiculo_id' => $vehiculo->id,
                'patente' => $vehiculo->patente,
                'vehiculo' => trim("$vehiculo->marca $vehiculo->modelo"),
                'finalizados' => $porVehiculo->get($vehiculo->id, collect())->count(),
                'km' => $this->sumarKm($porVehiculo->get($vehiculo->id, collect()), $kmPorViaje),
                'horas_turno' => round($horas->get($vehiculo->id, 0) / 3600, 1),
            ])
            ->values()
            ->all();
    }

    /** Viajes finalizados en el rango (los de un chofer cuentan para él y para el vehículo del viaje). */
    private function finalizados(Carbon $inicio, Carbon $fin): Builder
    {
        return Viaje::where('estado', EstadoViaje::Finalizado)
            ->whereNotNull('chofer_id')
            ->where('finalizado_en', '>=', $inicio)
            ->where('finalizado_en', '<', $fin);
    }

    /**
     * Metros recorridos por viaje finalizado en el rango (ver KilometrosRecorridos).
     *
     * @return array<int, float>
     */
    private function kmPorViaje(Carbon $inicio, Carbon $fin): array
    {
        return KilometrosRecorridos::metrosPorViaje($this->finalizados($inicio, $fin)->select('id'));
    }

    /** @param  Collection<int, Viaje>  $viajes */
    private function sumarKm(Collection $viajes, array $metrosPorViaje): float
    {
        return round($viajes->sum(fn (Viaje $v) => $metrosPorViaje[$v->id] ?? 0) / 1000, 2);
    }

    /**
     * Segundos de turno dentro del rango, agrupados por $columna (chofer_id o vehiculo_id).
     * Cada turno se recorta al rango; los abiertos cuentan hasta ahora.
     *
     * @return Collection<int, int>
     */
    private function horasDeTurno(Carbon $inicio, Carbon $fin, string $columna): Collection
    {
        $tope = $fin->copy()->min(now());

        return Turno::where('inicio', '<', $fin)
            ->where(fn (Builder $q) => $q->whereNull('fin')->orWhere('fin', '>', $inicio))
            ->get([$columna, 'inicio', 'fin'])
            ->groupBy($columna)
            ->map(fn (Collection $turnos) => $turnos->sum(function (Turno $t) use ($inicio, $tope) {
                $desde = $t->inicio->max($inicio);
                $hasta = ($t->fin ?? now())->min($tope);

                return max(0, $desde->diffInSeconds($hasta, false));
            }));
    }

    /** @return array{0: Carbon, 1: Carbon} inicio del día local $desde y fin (exclusivo) del día local $hasta, en UTC */
    private function limites(string $desde, string $hasta): array
    {
        return [
            Carbon::parse($desde, $this->zona())->startOfDay()->utc(),
            Carbon::parse($hasta, $this->zona())->startOfDay()->addDay()->utc(),
        ];
    }

    private function zona(): string
    {
        return config('vehiculos.zona_horaria');
    }
}
