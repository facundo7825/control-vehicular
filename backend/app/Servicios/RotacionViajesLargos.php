<?php

namespace App\Servicios;

use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Support\HoraLocal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A quién le toca el próximo viaje largo: al que hace más tiempo que no hace uno (finalizado). Los que nunca
 * hicieron uno van primero; los empates, por nombre.
 */
class RotacionViajesLargos
{
    public const DIAS_RECIENTES = 90;

    public function __construct(private DisponibilidadReservas $disponibilidad) {}

    /**
     * Choferes activos libres en la franja [salida, regreso], ordenados por rotación.
     *
     * @return Collection<int, array{chofer: Usuario, ultimo: ?array{viaje_id: int, fecha: Carbon, destino: ?string}, recientes: int, proximo: ?Viaje}>
     */
    public function ordenados(Carbon $salida, Carbon $regreso): Collection
    {
        $duracion = (int) $salida->diffInMinutes($regreso);

        return $this->ordenar($this->choferesActivos()
            ->filter(fn (Usuario $c) => $this->disponibilidad->estaDisponible($c->id, $salida, $duracion)));
    }

    /**
     * Todos los choferes activos, ordenados por rotación (para la página de rotación).
     *
     * @return Collection<int, array{chofer: Usuario, ultimo: ?array{viaje_id: int, fecha: Carbon, destino: ?string}, recientes: int, proximo: ?Viaje}>
     */
    public function todos(): Collection
    {
        return $this->ordenar($this->choferesActivos());
    }

    /** @return Collection<int, Usuario> */
    private function choferesActivos(): Collection
    {
        return Usuario::where('rol', RolUsuario::Chofer)->where('activo', true)->get();
    }

    /** @param  Collection<int, Usuario>  $choferes */
    private function ordenar(Collection $choferes): Collection
    {
        $ids = $choferes->modelKeys();

        $finalizados = Viaje::where('tipo', TipoViaje::Largo)
            ->where('estado', EstadoViaje::Finalizado)
            ->whereIn('chofer_id', $ids)
            ->orderByDesc('programado_para')
            ->orderByDesc('id')
            ->get(['id', 'chofer_id', 'programado_para', 'destino_direccion'])
            ->groupBy('chofer_id');

        $proximos = Viaje::where('tipo', TipoViaje::Largo)
            ->where('estado', EstadoViaje::Aceptado)
            ->where('programado_para', '>=', now())
            ->whereIn('chofer_id', $ids)
            ->orderBy('programado_para')
            ->get()
            ->groupBy('chofer_id');

        $desde = now()->subDays(self::DIAS_RECIENTES);

        return $choferes
            ->map(function (Usuario $c) use ($finalizados, $proximos, $desde) {
                $suyos = $finalizados->get($c->id, collect());
                $ultimo = $suyos->first();

                return [
                    'chofer' => $c,
                    'ultimo' => $ultimo ? [
                        'viaje_id' => $ultimo->id,
                        'fecha' => $ultimo->programado_para,
                        'destino' => $ultimo->destino_direccion,
                    ] : null,
                    'recientes' => $suyos->filter(fn (Viaje $v) => $v->programado_para->gte($desde))->count(),
                    'proximo' => $proximos->get($c->id)?->first(),
                ];
            })
            // Nunca (null) primero; después el último más antiguo; empates por nombre y, por las dudas, por id.
            ->sort(fn (array $a, array $b) => [
                $a['ultimo'] !== null, $a['ultimo']['fecha'] ?? null, mb_strtolower($a['chofer']->nombre), $a['chofer']->id,
            ] <=> [
                $b['ultimo'] !== null, $b['ultimo']['fecha'] ?? null, mb_strtolower($b['chofer']->nombre), $b['chofer']->id,
            ])
            ->values();
    }

    /** "12/09 (Tinogasta)" o "Nunca", para el select del formulario. */
    public static function etiquetaUltimo(?array $ultimo): string
    {
        if ($ultimo === null) {
            return 'Nunca';
        }

        $fecha = HoraLocal::formatear($ultimo['fecha'], 'd/m');

        return $ultimo['destino'] ? "$fecha ({$ultimo['destino']})" : $fecha;
    }
}
