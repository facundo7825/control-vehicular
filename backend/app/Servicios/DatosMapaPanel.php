<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Models\Viaje;
use App\Support\HoraLocal;

/** Lo que dibuja el mapa en vivo del panel (spec 8.1): choferes en turno y viajes activos. */
class DatosMapaPanel
{
    /** Colores del marcador de cada chofer según su estado (spec 4.1). */
    public const COLORES = [
        'libre' => '#16a34a',
        'en_viaje' => '#2563eb',
        'reservado_pronto' => '#d97706',
        'sin_senal' => '#dc2626',
    ];

    public function __construct(private CalculadorEstadoChofer $estados) {}

    /**
     * @return array{
     *     choferes: list<array{id: int, nombre: string, estado: string, estado_etiqueta: string, color: string, lat: float, lng: float, patente: ?string, actualizado_en: string}>,
     *     viajes: list<array{id: int, estado: string, estado_etiqueta: string, chofer_id: int, chofer: string, origen: array{lat: float, lng: float, direccion: ?string}, destino: array{lat: float, lng: float, direccion: ?string}}>
     * }
     */
    public function obtener(): array
    {
        $choferes = $this->estados->choferesEnTurno()
            // Sin ninguna ubicación todavía no hay dónde dibujarlo.
            ->filter(fn (array $f) => $f['chofer']->ubicacion !== null)
            ->map(function (array $f): array {
                /** @var EstadoChofer $estado */
                $estado = $f['estado'];
                $chofer = $f['chofer'];

                return [
                    'id' => $chofer->id,
                    'nombre' => $chofer->nombre,
                    'estado' => $estado->value,
                    'estado_etiqueta' => $estado->getLabel(),
                    'color' => self::COLORES[$estado->value] ?? '#6b7280',
                    'lat' => $chofer->ubicacion->lat,
                    'lng' => $chofer->ubicacion->lng,
                    'patente' => $chofer->turnoAbierto?->vehiculo?->patente,
                    'actualizado_en' => HoraLocal::formatear($chofer->ubicacion->actualizado_en, 'H:i:s'),
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
}
