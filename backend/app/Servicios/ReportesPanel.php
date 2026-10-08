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
    /** @return array{desde: string, hasta: string} el mes actual en días locales */
    public function rangoPorDefecto(): array
    {
        $hoy = Carbon::now($this->zona());

        return [
            'desde' => $hoy->copy()->startOfMonth()->format('Y-m-d'),
            'hasta' => $hoy->copy()->endOfMonth()->format('Y-m-d'),
        ];
    }

    /**
     * Aviso si en el rango hay viajes finalizados sin metros guardados: los finalizados antes de que se guardara
     * la distancia cuyo recorrido ya se había borrado por la retención (la migración rellenó el resto).
     */
    public function avisoKmSinDatos(string $desde, string $hasta): ?string
    {
        [$inicio, $fin] = $this->limites($desde, $hasta);
        $sinDatos = $this->finalizados($inicio, $fin)->whereNull('metros_recorridos')->count();

        return match ($sinDatos) {
            0 => null,
            1 => '1 viaje finalizado del rango no tiene km: su recorrido se borró antes de que se guardara la distancia.',
            default => "$sinDatos viajes finalizados del rango no tienen km: su recorrido se borró antes de que se guardara la distancia.",
        };
    }

    /**
     * @return list<array{chofer_id: int, chofer: string, finalizados: int, cancelados: int, km: float,
     *                    horas_turno: float, horas_largos: float, llegada_promedio_min: float|null}>
     */
    public function porChofer(string $desde, string $hasta): array
    {
        [$inicio, $fin] = $this->limites($desde, $hasta);

        $finalizados = $this->finalizados($inicio, $fin)
            ->get(['id', 'chofer_id', 'tipo', 'metros_recorridos', 'programado_para', 'iniciado_en', 'finalizado_en']);
        $cancelados = Viaje::where('estado', EstadoViaje::Cancelado)
            ->whereNotNull('chofer_id')
            ->where('cancelado_en', '>=', $inicio)
            ->where('cancelado_en', '<', $fin)
            ->pluck('chofer_id')
            ->countBy();
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
                'km' => $this->sumarKm($porChofer->get($chofer->id, collect())),
                'horas_turno' => round($horas->get($chofer->id, 0) / 3600, 1),
                'horas_largos' => $this->horasEnViajesLargos($porChofer->get($chofer->id, collect())),
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

        $finalizados = $this->finalizados($inicio, $fin)->whereNotNull('vehiculo_id')->get(['id', 'vehiculo_id', 'metros_recorridos']);
        $horas = $this->horasDeTurno($inicio, $fin, 'vehiculo_id');

        $ids = $finalizados->pluck('vehiculo_id')->merge($horas->keys())->filter()->unique();
        $porVehiculo = $finalizados->groupBy('vehiculo_id');

        return Vehiculo::whereIn('id', $ids)->orderBy('patente')->get(['id', 'patente', 'marca', 'modelo'])
            ->map(fn (Vehiculo $vehiculo) => [
                'vehiculo_id' => $vehiculo->id,
                'patente' => $vehiculo->patente,
                'vehiculo' => trim("$vehiculo->marca $vehiculo->modelo"),
                'finalizados' => $porVehiculo->get($vehiculo->id, collect())->count(),
                'km' => $this->sumarKm($porVehiculo->get($vehiculo->id, collect())),
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
     * Km de los viajes con los metros guardados al finalizar (ver KilometrosRecorridos); los que no tienen
     * dato suman 0 y avisoKmSinDatos lo advierte.
     *
     * @param  Collection<int, Viaje>  $viajes
     */
    private function sumarKm(Collection $viajes): float
    {
        return round($viajes->sum(fn (Viaje $v) => $v->metros_recorridos ?? 0) / 1000, 2);
    }

    /**
     * Horas reales (del inicio, o la salida programada, al fin; ver HorarioLaboral) de los viajes largos
     * finalizados en el rango. Cada viaje cuenta entero en el día en que terminó.
     *
     * @param  Collection<int, Viaje>  $viajes
     */
    private function horasEnViajesLargos(Collection $viajes): float
    {
        $horario = app(HorarioLaboral::class);

        return round($viajes
            ->filter(fn (Viaje $v) => $v->tipo === TipoViaje::Largo)
            ->sum(fn (Viaje $v) => $horario->duracionRealMin($v) ?? 0) / 60, 1);
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
