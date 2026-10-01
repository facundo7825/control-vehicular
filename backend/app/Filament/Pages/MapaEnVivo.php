<?php

namespace App\Filament\Pages;

use App\Servicios\DatosMapaPanel;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Mapa en vivo (spec 8.1). Sin Echo/Reverb en el panel (v1): Livewire consulta cada 10 s y le pasa
 * los datos nuevos al mapa con un evento del navegador; el mapa no se vuelve a dibujar (wire:ignore).
 * Con clave de Google usa Google Maps; sin clave, Leaflet con los tiles de OpenStreetMap (desarrollo/demos).
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

    /** Lo llama wire:poll; el script del mapa escucha el evento y actualiza los marcadores. */
    public function refrescar(): void
    {
        $this->dispatch('mapa-datos', datos: $this->datosMapa());
    }
}
