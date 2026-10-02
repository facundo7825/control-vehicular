<?php

namespace App\Filament\Pages;

use App\Mapas\Teselas;
use App\Servicios\DatosMapaPanel;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Mapa en vivo (spec 8.1). Sin Echo/Reverb en el panel (v1): Livewire consulta cada 10 s y le pasa
 * los datos nuevos al mapa con un evento del navegador; el mapa no se vuelve a dibujar (wire:ignore).
 * Con clave de Google usa Google Maps; sin clave, Leaflet con el mapa de fondo configurado (por defecto los tiles
 * de OpenStreetMap, solo para desarrollo/demos).
 */
class MapaEnVivo extends Page
{
    /** Leaflet desde unpkg con Subresource Integrity (versión fija: el hash depende del archivo exacto). */
    public const LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';

    public const LEAFLET_CSS_SRI = 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=';

    public const LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';

    public const LEAFLET_JS_SRI = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Mapa en vivo';

    protected static ?string $title = 'Mapa en vivo';

    protected static ?string $slug = 'mapa';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.mapa-en-vivo';

    /** Se calcula una vez por pedido: lo usan el evento del polling y la lista de la vista. */
    private ?array $datos = null;

    /** @return array{choferes: array, viajes: array} */
    public function datosMapa(): array
    {
        return $this->datos ??= app(DatosMapaPanel::class)->obtener();
    }

    /** Choferes en turno que todavía no mandaron ninguna ubicación: no se pueden poner en el mapa. */
    public function choferesSinUbicacion(): array
    {
        return array_values(array_filter($this->datosMapa()['choferes'], fn (array $c) => $c['lat'] === null));
    }

    public function claveGoogle(): ?string
    {
        return config('vehiculos.mapas.google_js_api_key') ?: config('vehiculos.mapas.google_api_key') ?: null;
    }

    /** El mapa de fondo de Leaflet: el mismo que usa la app (`vehiculos.mapas.teselas`). */
    public function teselas(): array
    {
        return Teselas::configuradas();
    }

    /**
     * Los créditos del mapa de fondo para Leaflet (que los muestra como HTML). Vienen de la configuración, así que
     * se escapan igual; el enlace solo se agrega si es http(s).
     */
    public function atribucionTeselas(): string
    {
        $t = $this->teselas();
        $texto = e($t['atribucion']);
        $enlace = $t['atribucion_url'];
        if ($enlace === null || ! in_array(strtolower((string) parse_url($enlace, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return $texto;
        }

        return '<a href="'.e($enlace).'" target="_blank" rel="noopener">'.$texto.'</a>';
    }

    /** Lo llama wire:poll; el script del mapa escucha el evento y actualiza los marcadores. */
    public function refrescar(): void
    {
        $this->dispatch('mapa-datos', datos: $this->datosMapa());
    }
}
