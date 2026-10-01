<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Excepciones\ReglaNegocio;
use App\Filament\Resources\Usuarios\UsuarioResource;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Mapas\ServicioRutas;
use App\Models\Turno;
use App\Models\Viaje;
use App\Support\HoraLocal;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lo que dibuja el mapa en vivo del panel (spec 8.1): todos los choferes en turno y los viajes activos.
 * Las consultas van agrupadas (una cantidad fija sin importar cuántos choferes haya); lo único por viaje
 * activo es la llegada estimada, que el estimador cachea.
 */
class DatosMapaPanel
{
    /** Colores del marcador de cada chofer según su estado (spec 4.1). */
    public const COLORES = [
        'libre' => '#16a34a',
        'en_viaje' => '#2563eb',
        'reservado_pronto' => '#d97706',
        'sin_senal' => '#6b7280',
    ];

    public function __construct(
        private CalculadorEstadoChofer $estados,
        private ServicioRutas $rutas,
        private EstimadorLlegada $estimador,
    ) {}

    /**
     * Los choferes que todavía no mandaron ninguna ubicación vienen con lat/lng en null: el mapa no
     * puede ubicarlos y la página los lista aparte.
     *
     * @return array{
     *     choferes: list<array{id: int, nombre: string, estado: string, estado_etiqueta: string, color: string, lat: ?float, lng: ?float, patente: ?string, actualizado_en: ?string, actualizado_hace: ?string, url: string,
     *         viaje: ?array{id: int, estado: string, estado_etiqueta: string, solicitante: ?string, hacia: string, hacia_direccion: ?string, llega_en_min: ?int, url: string},
     *         hoy: array{viajes: int, km: float, turno_desde: ?string}}>,
     *     viajes: list<array{id: int, estado: string, estado_etiqueta: string, chofer_id: int, chofer: string, origen: array{lat: float, lng: float, direccion: ?string}, destino: array{lat: float, lng: float, direccion: ?string}, recorrido: ?list<array{0: float, 1: float}>, url: string}>
     * }
     */
    public function obtener(): array
    {
        $enTurno = $this->estados->choferesEnTurno();
        // chofer.ubicacion: el estimador la usa y así no la consulta viaje por viaje.
        $activos = Viaje::activos()->with(['chofer.ubicacion', 'solicitante'])->orderBy('id')->get();
        // Si un chofer tuviera más de un viaje activo, el globo muestra el más viejo.
        $viajePorChofer = $activos->unique('chofer_id')->keyBy('chofer_id');
        $hoy = $this->hoy($enTurno->map(fn (array $f) => $f['chofer']->id)->all());

        $choferes = $enTurno
            ->map(function (array $f) use ($viajePorChofer, $hoy): array {
                /** @var EstadoChofer $estado */
                $estado = $f['estado'];
                $chofer = $f['chofer'];
                $ubicacion = $chofer->ubicacion;
                $viaje = $viajePorChofer->get($chofer->id);

                return [
                    'id' => $chofer->id,
                    'nombre' => $chofer->nombre,
                    'estado' => $estado->value,
                    'estado_etiqueta' => $estado->getLabel(),
                    'color' => self::COLORES[$estado->value] ?? self::COLORES['sin_senal'],
                    'lat' => $ubicacion?->lat,
                    'lng' => $ubicacion?->lng,
                    'patente' => $chofer->turnoAbierto?->vehiculo?->patente,
                    'actualizado_en' => $ubicacion ? HoraLocal::formatear($ubicacion->actualizado_en, 'H:i:s') : null,
                    'actualizado_hace' => $ubicacion ? self::hace($ubicacion->actualizado_en) : null,
                    'url' => UsuarioResource::getUrl('edit', ['record' => $chofer->id]),
                    'viaje' => $viaje ? $this->viajeDelChofer($viaje) : null,
                    'hoy' => [
                        'viajes' => $hoy[$chofer->id]['viajes'] ?? 0,
                        'km' => round(($hoy[$chofer->id]['metros'] ?? 0) / 1000, 1),
                        'turno_desde' => self::turnoDesde($chofer->turnoAbierto),
                    ],
                ];
            })
            ->values()
            ->all();

        $viajes = $activos
            ->map(fn (Viaje $v): array => [
                'id' => $v->id,
                'estado' => $v->estado->value,
                'estado_etiqueta' => $v->estado->getLabel(),
                'chofer_id' => $v->chofer_id,
                'chofer' => $v->chofer->nombre,
                'origen' => ['lat' => $v->origen_lat, 'lng' => $v->origen_lng, 'direccion' => $v->origen_direccion],
                'destino' => ['lat' => $v->destino_lat, 'lng' => $v->destino_lng, 'direccion' => $v->destino_direccion],
                // El camino por calles entre origen y destino (cacheado por el servicio); null = sin recorrido, el mapa
                // une los puntos con una línea recta punteada.
                'recorrido' => $this->rutas->ruta($v->origen_lat, $v->origen_lng, $v->destino_lat, $v->destino_lng)['puntos'] ?? null,
                'url' => ViajeResource::getUrl('view', ['record' => $v->id]),
            ])
            ->all();

        return ['choferes' => $choferes, 'viajes' => $viajes];
    }

    /** El viaje actual para el globo del chofer: a quién lleva, hacia dónde va y en cuánto llega. */
    private function viajeDelChofer(Viaje $viaje): array
    {
        $hacia = $viaje->estado === EstadoViaje::EnCurso ? 'destino' : 'origen';

        return [
            'id' => $viaje->id,
            'estado' => $viaje->estado->value,
            'estado_etiqueta' => $viaje->estado->getLabel(),
            'solicitante' => $viaje->solicitante?->nombre,
            'hacia' => $hacia,
            'hacia_direccion' => $hacia === 'origen' ? $viaje->origen_direccion : $viaje->destino_direccion,
            'llega_en_min' => $this->minutosDeLlegada($viaje),
            'url' => ViajeResource::getUrl('view', ['record' => $viaje->id]),
        ];
    }

    /** Minutos hasta llegar, redondeados hacia arriba; null = sin estimación (sin señal o el estimador falló). */
    private function minutosDeLlegada(Viaje $viaje): ?int
    {
        try {
            $segundos = $this->estimador->estimar($viaje)['segundos'];
        } catch (ReglaNegocio) {
            return null; // el viaje cambió de estado entre la consulta y la estimación
        } catch (Throwable $e) {
            report($e); // el servicio de mapas falló: el mapa sigue, sin estimación

            return null;
        }

        return $segundos === null ? null : (int) ceil($segundos / 60);
    }

    /**
     * Viajes finalizados en el día local y metros recorridos en ellos (los guardados al finalizar cada viaje),
     * por chofer. Una consulta agrupada para todos los choferes.
     *
     * @param  list<int>  $choferIds
     * @return array<int, array{viajes: int, metros: float}>
     */
    private function hoy(array $choferIds): array
    {
        $desde = now()->setTimezone(config('vehiculos.zona_horaria'))->startOfDay()->setTimezone(config('app.timezone'));

        return Viaje::whereIn('chofer_id', $choferIds)
            ->where('estado', EstadoViaje::Finalizado)
            ->where('finalizado_en', '>=', $desde)
            ->groupBy('chofer_id')
            ->toBase()
            ->get(['chofer_id', DB::raw('COUNT(*) as viajes'), DB::raw('COALESCE(SUM(metros_recorridos), 0) as metros')])
            ->mapWithKeys(fn (object $f) => [(int) $f->chofer_id => ['viajes' => (int) $f->viajes, 'metros' => (float) $f->metros]])
            ->all();
    }

    /** "07:30" si el turno empezó hoy (hora local); "30/09 22:00" si viene de un día anterior. */
    private static function turnoDesde(?Turno $turno): ?string
    {
        if ($turno === null) {
            return null;
        }
        $zona = config('vehiculos.zona_horaria');
        $deHoy = $turno->inicio->copy()->setTimezone($zona)->isSameDay(now()->setTimezone($zona));

        return HoraLocal::formatear($turno->inicio, $deHoy ? 'H:i' : 'd/m H:i');
    }

    /** "hace 5 min", "hace 1 h 15 min": cuánto pasó desde la última ubicación. */
    private static function hace(DateTimeInterface $momento): string
    {
        $minutos = max(0, (int) floor((now()->getTimestamp() - $momento->getTimestamp()) / 60));

        return match (true) {
            $minutos < 1 => 'hace menos de 1 min',
            $minutos < 60 => "hace {$minutos} min",
            $minutos % 60 === 0 => 'hace '.intdiv($minutos, 60).' h',
            default => 'hace '.intdiv($minutos, 60).' h '.($minutos % 60).' min',
        };
    }
}
