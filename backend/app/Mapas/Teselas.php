<?php

namespace App\Mapas;

/**
 * El mapa de fondo configurado (`vehiculos.mapas.teselas`), con los tipos normalizados: lo usan
 * `/api/configuracion` (la app) y el mapa en vivo del panel (Leaflet).
 */
class Teselas
{
    /** @return array{url: string, atribucion: string, atribucion_url: ?string, tms: bool, max_zoom: int} */
    public static function configuradas(): array
    {
        $c = (array) config('vehiculos.mapas.teselas', []);
        $enlace = trim((string) ($c['atribucion_url'] ?? ''));

        return [
            'url' => (string) ($c['url'] ?? ''),
            'atribucion' => (string) ($c['atribucion'] ?? ''),
            'atribucion_url' => $enlace === '' ? null : $enlace,
            'tms' => filter_var($c['tms'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'max_zoom' => (int) ($c['max_zoom'] ?? 19),
        ];
    }
}
