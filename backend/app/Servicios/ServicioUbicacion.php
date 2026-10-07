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
        $turno = $chofer->turnoAbierto()->first();
        $conTurno = $turno !== null;

        $puntos = collect($puntos)
            ->map(fn (array $p) => [...$p, 'momento' => HoraLocal::interpretar($p['registrado_en'])->min(now())])
            ->filter(fn (array $p) => $p['momento']->gte(now()->subHours(self::ANTIGUEDAD_MAXIMA_H)))
            ->sortBy('momento')
            ->values();

        $filas = $this->filasDelRecorrido($chofer, $puntos);
        if (! $conTurno && $filas->isEmpty()) {
            throw new ReglaNegocio('Iniciá un turno para compartir tu ubicación.');
        }

        // La ubicación actual sale solo de puntos de este turno: lo atrasado de un turno anterior no es la posición en vivo.
        $delTurno = $conTurno ? $puntos->filter(fn (array $p) => $p['momento']->gte($turno->inicio)) : collect();
        if ($delTurno->isNotEmpty()) {
            $this->actualizarUbicacion($chofer, $delTurno->last());
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
     *
     * @param  Collection<int, array<string, mixed>>  $puntos  ordenados por momento
     * @return Collection<int, array{viaje_id: int, lat: float, lng: float, registrado_en: Carbon}>
     */
    private function filasDelRecorrido(Usuario $chofer, Collection $puntos): Collection
    {
        if ($puntos->isEmpty()) {
            return collect();
        }
        $viajes = $this->viajesDelLote($chofer->id, $puntos->first()['momento'], $puntos->last()['momento']);

        return $puntos
            ->map(function (array $p) use ($viajes) {
                $viaje = $viajes->first(fn (Viaje $v) => self::contiene($v, $p['momento']));

                return $viaje
                    ? ['viaje_id' => $viaje->id, 'lat' => $p['lat'], 'lng' => $p['lng'], 'registrado_en' => $p['momento']]
                    : null;
            })
            ->filter()
            ->values();
    }

    /**
     * Los viajes del chofer que se superponen con [$desde, $hasta], del más nuevo al más viejo: un punto justo en
     * el fin de un viaje y el inicio del siguiente va al siguiente. Consultas acotadas por los índices del chofer:
     * el viaje en curso y los terminados (finalizados o cancelados ya iniciados) desde el primer punto.
     *
     * @return Collection<int, Viaje>
     */
    private function viajesDelLote(int $choferId, Carbon $desde, Carbon $hasta): Collection
    {
        $columnas = ['id', 'iniciado_en', 'finalizado_en', 'cancelado_en'];
        $enCurso = Viaje::where('chofer_id', $choferId)->where('estado', EstadoViaje::EnCurso)->get($columnas);
        $finalizados = Viaje::where('chofer_id', $choferId)->where('finalizado_en', '>=', $desde)
            ->where('iniciado_en', '<=', $hasta)->get($columnas);
        $cancelados = Viaje::where('chofer_id', $choferId)->where('estado', EstadoViaje::Cancelado)
            ->where('cancelado_en', '>=', $desde)->where('iniciado_en', '<=', $hasta)->get($columnas);

        return $enCurso->concat($finalizados)->concat($cancelados)
            ->filter(fn (Viaje $v) => $v->iniciado_en !== null)
            ->unique('id')
            ->sortByDesc(fn (Viaje $v) => $v->iniciado_en->getTimestamp())
            ->values();
    }

    private static function contiene(Viaje $viaje, Carbon $momento): bool
    {
        return $momento->gte($viaje->iniciado_en) && $momento->lte($viaje->finalizado_en ?? $viaje->cancelado_en ?? now());
    }

    /**
     * Al finalizar con una hora anterior a la de puntos ya recibidos (el "Finalizar" llegó tarde), esos puntos
     * no son de este viaje: pasan al viaje del chofer que los contiene, si hay alguno, o se borran. Corre en la
     * transacción de la máquina de estados, con el viaje bloqueado; no toca la fila de otro viaje.
     */
    public function recortarAlFinalizar(Viaje $viaje): void
    {
        $fin = $viaje->finalizado_en;
        $sobrantes = fn () => PuntoRecorrido::where('viaje_id', $viaje->id)->where('registrado_en', '>', $fin);
        $hasta = $sobrantes()->max('registrado_en');
        if ($hasta === null || $viaje->chofer_id === null) {
            return;
        }
        $hasta = Carbon::parse($hasta);

        $otros = Viaje::where('chofer_id', $viaje->chofer_id)->whereKeyNot($viaje->id)
            ->where('iniciado_en', '>=', $viaje->iniciado_en ?? $fin)->where('iniciado_en', '<=', $hasta)
            ->orderByDesc('iniciado_en')
            ->get(['id', 'iniciado_en', 'finalizado_en', 'cancelado_en']);

        foreach ($otros as $otro) {
            $desde = $otro->iniciado_en->copy()->max($fin);
            $hastaOtro = $otro->finalizado_en ?? $otro->cancelado_en ?? now();
            $enRango = fn () => $sobrantes()->where('registrado_en', '>=', $desde)->where('registrado_en', '<=', $hastaOtro);
            // Los que el otro viaje ya tiene (mismo momento) se descartan: el índice único no admite repetidos.
            $repetidos = PuntoRecorrido::where('viaje_id', $otro->id)
                ->whereBetween('registrado_en', [$desde, $hastaOtro])->pluck('registrado_en');
            if ($repetidos->isNotEmpty()) {
                $enRango()->whereIn('registrado_en', $repetidos->all())->delete();
            }
            $enRango()->update(['viaje_id' => $otro->id]);
        }

        $sobrantes()->delete();
    }

    /**
     * Si el viaje ya estaba finalizado (puntos que llegaron tarde) o se finalizó mientras se guardaban, sus metros
     * se guardaron sin estos puntos: se recalculan. El bloqueo espera a que termine una finalización en marcha;
     * si esa finalización fue con una hora anterior a estos puntos (un "Finalizar" atrasado), se vuelven a recortar.
     */
    private function recalcularSiYaFinalizo(int $viajeId): void
    {
        DB::transaction(function () use ($viajeId) {
            $viaje = Viaje::whereKey($viajeId)->lockForUpdate()->first();
            if ($viaje?->estado === EstadoViaje::Finalizado) {
                $this->recortarAlFinalizar($viaje);
                Viaje::whereKey($viajeId)->update(['metros_recorridos' => KilometrosRecorridos::metrosDe($viajeId)]);
            }
        });
    }
}
