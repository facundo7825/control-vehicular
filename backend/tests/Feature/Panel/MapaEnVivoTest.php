<?php

use App\Enums\EstadoViaje;
use App\Filament\Pages\MapaEnVivo;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\DatosMapaPanel;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $this->actingAs(Usuario::factory()->admin()->create());
});

it('arma todos los choferes en turno con su color y los viajes activos con origen y destino', function () {
    $libre = choferEnTurno(-34.60, -58.38);
    $enViaje = choferEnTurno(-34.61, -58.39, minutos: 2);
    $sinSenal = choferEnTurno(-34.62, -58.40, minutos: 75);
    $sinUbicacion = Turno::factory()->create()->chofer; // en turno pero nunca mandó ubicación
    $viaje = Viaje::factory()->create([
        'chofer_id' => $enViaje->id, 'estado' => EstadoViaje::EnCamino,
        'origen_direccion' => 'Tribunales', 'destino_direccion' => 'Casa de Gobierno',
    ]);
    Viaje::factory()->create(['estado' => EstadoViaje::Buscando]); // sin chofer: no es activo

    $datos = app(DatosMapaPanel::class)->obtener();

    expect(collect($datos['choferes'])->pluck('color', 'id')->all())->toBe([
        $libre->id => '#16a34a',
        $enViaje->id => '#2563eb',
        $sinSenal->id => '#6b7280',
        $sinUbicacion->id => '#6b7280',
    ]);
    expect($datos['choferes'][0])->toMatchArray([
        'nombre' => $libre->nombre,
        'estado' => 'libre',
        'estado_etiqueta' => 'Libre',
        'lat' => -34.60,
        'lng' => -58.38,
        'patente' => $libre->turnoAbierto->vehiculo->patente,
        'actualizado_en' => '09:00:00',
        'actualizado_hace' => 'hace menos de 1 min',
    ]);
    expect($datos['choferes'][1]['actualizado_hace'])->toBe('hace 2 min');
    expect($datos['choferes'][2])->toMatchArray([
        'estado' => 'sin_senal',
        'estado_etiqueta' => 'Sin señal',
        'actualizado_en' => '07:45:00',
        'actualizado_hace' => 'hace 1 h 15 min',
    ]);
    expect($datos['choferes'][3])->toMatchArray([
        'nombre' => $sinUbicacion->nombre,
        'estado' => 'sin_senal',
        'lat' => null,
        'lng' => null,
        'patente' => $sinUbicacion->turnoAbierto->vehiculo->patente,
        'actualizado_en' => null,
        'actualizado_hace' => null,
    ]);
    expect($datos['viajes'])->toBe([[
        'id' => $viaje->id,
        'estado' => 'en_camino',
        'estado_etiqueta' => 'En camino',
        'chofer_id' => $enViaje->id,
        'chofer' => $enViaje->nombre,
        'origen' => ['lat' => -34.6037, 'lng' => -58.3816, 'direccion' => 'Tribunales'],
        'destino' => ['lat' => -34.609, 'lng' => -58.392, 'direccion' => 'Casa de Gobierno'],
    ]]);
});

it('no incluye a los choferes sin turno abierto', function () {
    $enTurno = choferEnTurno();
    Usuario::factory()->chofer()->create();

    expect(collect(app(DatosMapaPanel::class)->obtener()['choferes'])->pluck('id')->all())->toBe([$enTurno->id]);
});

it('muestra el mapa de Google con la API key y refresca por polling', function () {
    config(['vehiculos.mapas.google_api_key' => 'clave-de-prueba']);
    choferEnTurno();

    Livewire::test(MapaEnVivo::class)
        ->assertOk()
        ->assertSeeHtml('wire:poll.10s="refrescar"')
        ->assertSeeHtml('id="mapa-en-vivo"')
        ->assertSeeHtml('data-proveedor="google"')
        ->assertDontSeeHtml('unpkg.com/leaflet')
        ->call('refrescar')
        ->assertDispatched('mapa-datos');

    $this->get(MapaEnVivo::getUrl())->assertOk()->assertSee('maps.googleapis.com', false);
});

it('prefiere la clave propia del mapa a la del servidor', function () {
    config(['vehiculos.mapas.google_api_key' => 'clave-servidor', 'vehiculos.mapas.google_js_api_key' => 'clave-navegador']);

    expect(Livewire::test(MapaEnVivo::class)->instance()->claveGoogle())->toBe('clave-navegador');
});

it('sin API key muestra el mapa de OpenStreetMap con Leaflet y refresca por polling', function () {
    config(['vehiculos.mapas.google_api_key' => null, 'vehiculos.mapas.google_js_api_key' => null]);
    choferEnTurno();

    Livewire::test(MapaEnVivo::class)
        ->assertOk()
        ->assertDontSee('Falta la API key de Google Maps')
        ->assertSeeHtml('wire:poll.10s="refrescar"')
        ->assertSeeHtml('id="mapa-en-vivo"')
        ->assertSeeHtml('data-proveedor="leaflet"')
        ->assertSeeHtml('wire:ignore')
        ->call('refrescar')
        ->assertDispatched('mapa-datos');

    $this->get(MapaEnVivo::getUrl())->assertOk()
        ->assertSee('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', false)
        ->assertSee('sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=', false)
        ->assertSee('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', false)
        ->assertSee('sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=', false)
        ->assertSee('tile.openstreetmap.org', false)
        ->assertSee('OpenStreetMap', false)
        ->assertDontSee('data-proveedor="google"', false);
});

it('lista aparte a los choferes en turno que todavía no mandaron ubicación', function () {
    $sinUbicacion = Turno::factory()->create()->chofer;
    choferEnTurno(); // con ubicación: va al mapa, no a la lista

    Livewire::test(MapaEnVivo::class)
        ->assertSee('Sin ubicación todavía')
        ->assertSee($sinUbicacion->nombre)
        ->assertSee($sinUbicacion->turnoAbierto->vehiculo->patente);
});
