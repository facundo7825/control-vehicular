<?php

namespace App\Mapas;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * El mapa de fondo configurado (`vehiculos.mapas.teselas`), con los tipos normalizados: lo usan
 * `/api/configuracion` (la app) y el mapa en vivo del panel (Leaflet).
 *
 * Valores vacíos o inválidos vuelven al valor por defecto (URL y atribución del OSM público, zoom 19); `tms`
 * acepta true/false, on/off, yes/no, 1/0 (vacío es falso). En producción, una URL http:// se avisa en el log
 * (una vez por día): el panel por HTTPS la bloquea como contenido mixto y Android no permite tráfico sin cifrar.
 */
class Teselas
{
    public const URL_POR_DEFECTO = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    public const ATRIBUCION_POR_DEFECTO = '© OpenStreetMap contributors';

    public const MAX_ZOOM_POR_DEFECTO = 19;

    private const CLAVE_AVISO_HTTP = 'mapas:teselas:aviso-http';

    /** @return array{url: string, atribucion: string, atribucion_url: ?string, tms: bool, max_zoom: int} */
    public static function configuradas(): array
    {
        $c = (array) config('vehiculos.mapas.teselas', []);
        $url = trim((string) ($c['url'] ?? '')) ?: self::URL_POR_DEFECTO;
        $atribucion = trim((string) ($c['atribucion'] ?? '')) ?: self::ATRIBUCION_POR_DEFECTO;
        $enlace = trim((string) ($c['atribucion_url'] ?? ''));
        $zoom = filter_var($c['max_zoom'] ?? null, FILTER_VALIDATE_INT);

        if (str_starts_with(strtolower($url), 'http://') && app()->environment('production')
            && Cache::add(self::CLAVE_AVISO_HTTP, true, 86400)) {
            Log::warning('MAPAS_TESELAS_URL usa http:// en producción: conviene HTTPS (contenido mixto en el panel, tráfico sin cifrar bloqueado en Android)');
        }

        return [
            'url' => $url,
            'atribucion' => $atribucion,
            'atribucion_url' => $enlace === '' ? null : $enlace,
            'tms' => filter_var($c['tms'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'max_zoom' => is_int($zoom) && $zoom > 0 ? $zoom : self::MAX_ZOOM_POR_DEFECTO,
        ];
    }
}
