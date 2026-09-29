<?php

namespace App\Filament\Pages;

use App\Servicios\DatosMapaPanel;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Mapa en vivo (spec 8.1). Sin Echo/Reverb en el panel (v1): Livewire consulta cada 10 s y le pasa
 * los datos nuevos al mapa con un evento del navegador; el mapa no se vuelve a dibujar (wire:ignore).
 */
class MapaEnVivo extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Mapa en vivo';

    protected static ?string $title = 'Mapa en vivo';

    protected static ?string $slug = 'mapa';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.mapa-en-vivo';

    /** @return array{choferes: array, viajes: array} */
    public function datosMapa(): array
    {
        return app(DatosMapaPanel::class)->obtener();
    }

    public function claveGoogle(): ?string
    {
        return config('vehiculos.mapas.google_js_api_key') ?: config('vehiculos.mapas.google_api_key') ?: null;
    }

    /** Lo llama wire:poll; el script del mapa escucha el evento y redibuja los marcadores. */
    public function refrescar(): void
    {
        $this->dispatch('mapa-datos', datos: $this->datosMapa());
    }
}
