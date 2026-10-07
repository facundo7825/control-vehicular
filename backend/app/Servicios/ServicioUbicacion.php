<?php

namespace App\Servicios;

use App\Enums\EstadoViaje;
use App\Events\UbicacionChoferActualizada;
use App\Excepciones\ReglaNegocio;
use App\Models\PuntoRecorrido;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Support\HoraLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioUbicacion
{
    /** Los puntos que la app guardó sin señal se aceptan hasta este tiempo después. */
    private const ANTIGUEDAD_MAXIMA_H = 24;

    public function __construct(private AvisoEstadoChofer $aviso) {}

    /**
     * Cada punto va al recorrido del viaje del chofer que lo contiene por su hora ([iniciado_en, fin o ahora]),
     * aunque llegue tarde (la app lo guardó sin señal) y el viaje ya haya terminado. La ubicación actual y el
     * estado del chofer se actualizan solo con turno abierto; sin turno, se aceptan solo los puntos de un viaje.
     *
     * @param  array<int, array{lat: float, lng: float, rumbo?: ?float, velocidad?: ?float, registrado_en: string}>  $puntos
     */
    public function registrar(Usuario $chofer, array $puntos): void
    {
        $conTurno = $chofer->turnoAbierto()->exists();

        $puntos = collect($puntos)
            ->map(fn (array $p) => [...$p, 'momento' => HoraLocal::interpretar($p['registrado_en'])->min(now())])
            ->filter(fn (array $p) => $p['momento']->gte(now()->subHours(self::ANTIGUEDAD_MAXIMA_H)))
            ->sortBy('momento')
            ->values();

        $filas = $this->filasDelRecorrido($chofer, $puntos);
        if (! $conTurno && $filas->isEmpty()) {
            throw new ReglaNegocio('Iniciá un turno para compartir tu ubicación.');
        }

        if ($conTurno && $puntos->isNotEmpty()) {
            $this->actualizarUbicacion($chofer, $puntos->last());
        }

        // Idempotente: un lote reenviado (la app no recibió el 204) no duplica puntos. El índice único
        // (viaje_id, registrado_en) descarta los que ya estaban, también dentro del mismo lote.
        foreach ($filas->groupBy('viaje_id') as $viajeId => $delViaje) {
            if (PuntoRecorrido::insertOrIgnore($delViaje->all()) > 0) {
                $this->recalcularSiYaFinalizo($viajeId);
            }
        }
    }

    /** @param  array{lat: float, lng: float, rumbo?: ?float, velocidad?: ?float, momento: Carbon}  $ultimo */
    private function actualizarUbicacion(Usuario $chofer, array $ultimo): void
    {
        $actual = UbicacionChofer::find($chofer->id);
        if (! $actual || $actual->actualizado_en->lte($ultimo['momento'])) {
            UbicacionChofer::updateOrCreate(['chofer_id' => $chofer->id], [
                'lat' => $ultimo['lat'],
                'lng' => $ultimo['lng'],
                'rumbo' => $ultimo['rumbo'] ?? null,
                'velocidad' => $ultimo['velocidad'] ?? null,
                'actualizado_en' => $ultimo['momento'],
            ]);
            UbicacionChoferActualizada::dispatch(
                $chofer->id,
                (float) $ultimo['lat'],
                (float) $ultimo['lng'],
                isset($ultimo['rumbo']) ? (float) $ultimo['rumbo'] : null,
                $ultimo['momento']->toIso8601String(),
                Viaje::activosDeChofer($chofer->id)->value('id'),
            );
        }

        $this->aviso->publicarSiCambio($chofer);
    }

    /**
     * Las filas de recorrido_viaje de los puntos que caen dentro del intervalo de algún viaje del chofer.
     * Se consultan solo los viajes que se superponen con el lote: empezados antes de su último punto y
     * todavía en marcha o terminados después del primero.
     *
     * @param  Collection<int, array<string, mixed>>  $puntos  ordenados por momento
     * @return Collection<int, array{viaje_id: int, lat: float, lng: float, registrado_en: Carbon}>
     */
    private function filasDelRecorrido(Usuario $chofer, Collection $puntos): Collection
    {
        if ($puntos->isEmpty()) {
            return collect();
        }
        $desde = $puntos->first()['momento'];
        $hasta = $puntos->last()['momento'];

        $viajes = Viaje::where('chofer_id', $chofer->id)
            ->whereNotNull('iniciado_en')
            ->where('iniciado_en', '<=', $hasta)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $enMarcha) => $enMarcha->whereNull('finalizado_en')->whereNull('cancelado_en'))
                ->orWhere('finalizado_en', '>=', $desde)
                ->orWhere('cancelado_en', '>=', $desde))
            ->get(['id', 'iniciado_en', 'finalizado_en', 'cancelado_en']);

        return $puntos
            ->map(function (array $p) use ($viajes) {
                $viaje = $viajes->first(fn (Viaje $v) => $p['momento']->gte($v->iniciado_en)
                    && $p['momento']->lte($v->finalizado_en ?? $v->cancelado_en ?? now()));

                return $viaje
                    ? ['viaje_id' => $viaje->id, 'lat' => $p['lat'], 'lng' => $p['lng'], 'registrado_en' => $p['momento']]
                    : null;
            })
            ->filter()
            ->values();
    }

    /**
     * Si el viaje ya estaba finalizado (puntos que llegaron tarde) o se finalizó mientras se guardaban, sus metros
     * se guardaron sin estos puntos: se recalculan. El bloqueo espera a que termine una finalización en marcha.
     */
    private function recalcularSiYaFinalizo(int $viajeId): void
    {
        DB::transaction(function () use ($viajeId) {
            $estado = Viaje::whereKey($viajeId)->lockForUpdate()->value('estado');
            if ($estado === EstadoViaje::Finalizado) {
                Viaje::whereKey($viajeId)->update(['metros_recorridos' => KilometrosRecorridos::metrosDe($viajeId)]);
            }
        });
    }
}
