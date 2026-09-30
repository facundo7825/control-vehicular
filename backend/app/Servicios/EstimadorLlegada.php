<?php

namespace App\Servicios;

use App\Enums\EstadoViaje;
use App\Excepciones\ReglaNegocio;
use App\Mapas\Distancia;
use App\Mapas\ServicioMapas;
use App\Models\Viaje;
use Illuminate\Support\Facades\Cache;

class EstimadorLlegada
{
    private const SEGUNDOS_CACHE = 30;

    public function __construct(private readonly ServicioMapas $mapas) {}

    /** @return array{hacia: string, segundos: ?int, metros: ?int, calculado_en: string} */
    public function estimar(Viaje $viaje): array
    {
        $hacia = match ($viaje->estado) {
            EstadoViaje::Aceptado, EstadoViaje::EnCamino, EstadoViaje::Llego => 'origen',
            EstadoViaje::EnCurso => 'destino',
            default => null,
        };

        if ($hacia === null || $viaje->chofer_id === null) {
            throw new ReglaNegocio('El viaje no tiene un chofer en camino.');
        }

        return Cache::remember(
            "eta:{$viaje->id}:{$hacia}",
            self::SEGUNDOS_CACHE,
            fn () => $this->calcular($viaje, $hacia),
        );
    }

    private function calcular(Viaje $viaje, string $hacia): array
    {
        $base = ['hacia' => $hacia, 'calculado_en' => now()->toIso8601String()];

        if ($viaje->estado === EstadoViaje::Llego) {
            return [...$base, 'segundos' => 0, 'metros' => 0];
        }

        $ubicacion = $viaje->chofer?->ubicacion;
        if ($ubicacion === null) {
            return [...$base, 'segundos' => null, 'metros' => null];
        }

        [$lat, $lng] = $hacia === 'origen'
            ? [$viaje->origen_lat, $viaje->origen_lng]
            : [$viaje->destino_lat, $viaje->destino_lng];

        $segundos = $this->mapas->duracionesHacia(
            [$viaje->chofer_id => [$ubicacion->lat, $ubicacion->lng]],
            $lat,
            $lng,
        )[$viaje->chofer_id] ?? null;

        return [
            ...$base,
            'segundos' => $segundos,
            'metros' => (int) round(Distancia::metros($ubicacion->lat, $ubicacion->lng, $lat, $lng)),
        ];
    }
}
