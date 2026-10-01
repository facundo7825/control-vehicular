<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Models\Viaje;
use App\Support\HoraLocal;
use DateTimeInterface;

/** Lo que dibuja el mapa en vivo del panel (spec 8.1): todos los choferes en turno y los viajes activos. */
class DatosMapaPanel
{
    /** Colores del marcador de cada chofer según su estado (spec 4.1). */
    public const COLORES = [
        'libre' => '#16a34a',
        'en_viaje' => '#2563eb',
        'reservado_pronto' => '#d97706',
        'sin_senal' => '#6b7280',
    ];

    public function __construct(private CalculadorEstadoChofer $estados) {}

    /**
     * Los choferes que todavía no mandaron ninguna ubicación vienen con lat/lng en null: el mapa no
     * puede ubicarlos y la página los lista aparte.
     *
     * @return array{
     *     choferes: list<array{id: int, nombre: string, estado: string, estado_etiqueta: string, color: string, lat: ?float, lng: ?float, patente: ?string, actualizado_en: ?string, actualizado_hace: ?string}>,
     *     viajes: list<array{id: int, estado: string, estado_etiqueta: string, chofer_id: int, chofer: string, origen: array{lat: float, lng: float, direccion: ?string}, destino: array{lat: float, lng: float, direccion: ?string}}>
     * }
     */
    public function obtener(): array
    {
        $choferes = $this->estados->choferesEnTurno()
            ->map(function (array $f): array {
                /** @var EstadoChofer $estado */
                $estado = $f['estado'];
                $chofer = $f['chofer'];
                $ubicacion = $chofer->ubicacion;

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
                ];
            })
            ->values()
            ->all();

        $viajes = Viaje::activos()
            ->with('chofer')
            ->orderBy('id')
            ->get()
            ->map(fn (Viaje $v): array => [
                'id' => $v->id,
                'estado' => $v->estado->value,
                'estado_etiqueta' => $v->estado->getLabel(),
                'chofer_id' => $v->chofer_id,
                'chofer' => $v->chofer->nombre,
                'origen' => ['lat' => $v->origen_lat, 'lng' => $v->origen_lng, 'direccion' => $v->origen_direccion],
                'destino' => ['lat' => $v->destino_lat, 'lng' => $v->destino_lng, 'direccion' => $v->destino_direccion],
            ])
            ->all();

        return ['choferes' => $choferes, 'viajes' => $viajes];
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
