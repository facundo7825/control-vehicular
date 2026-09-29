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

it('arma los choferes en turno con su color y los viajes activos con origen y destino', function () {
    $libre = choferEnTurno(-34.60, -58.38);
    $enViaje = choferEnTurno(-34.61, -58.39);
    $sinSenal = choferEnTurno(-34.62, -58.40, minutos: 5);
    Turno::factory()->create(); // en turno pero nunca mandó ubicación: no se dibuja
    $viaje = Viaje::factory()->create([
        'chofer_id' => $enViaje->id, 'estado' => EstadoViaje::EnCamino,
        'origen_direccion' => 'Tribunales', 'destino_direccion' => 'Casa de Gobierno',
    ]);
    Viaje::factory()->create(['estado' => EstadoViaje::Buscando]); // sin chofer: no es activo

    $datos = app(DatosMapaPanel::class)->obtener();

    expect(collect($datos['choferes'])->pluck('color', 'id')->all())->toBe([
        $libre->id => '#16a34a',
        $enViaje->id => '#2563eb',
        $sinSenal->id => '#dc2626',
    ]);
    expect($datos['choferes'][0])->toMatchArray([
        'nombre' => $libre->nombre,
        'estado' => 'libre',
        'estado_etiqueta' => 'Libre',
        'lat' => -34.60,
        'lng' => -58.38,
        'patente' => $libre->turnoAbierto->vehiculo->patente,
        'actualizado_en' => '09:00:00',
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

it('muestra el mapa con la API key y refresca por polling', function () {
    config(['vehiculos.mapas.google_api_key' => 'clave-de-prueba']);
    choferEnTurno();

    Livewire::test(MapaEnVivo::class)
        ->assertOk()
        ->assertSeeHtml('wire:poll.10s="refrescar"')
        ->assertSeeHtml('id="mapa-en-vivo"')
        ->call('refrescar')
        ->assertDispatched('mapa-datos');

    $this->get(MapaEnVivo::getUrl())->assertOk();
});

it('prefiere la clave propia del mapa a la del servidor', function () {
    config(['vehiculos.mapas.google_api_key' => 'clave-servidor', 'vehiculos.mapas.google_js_api_key' => 'clave-navegador']);

    expect(Livewire::test(MapaEnVivo::class)->instance()->claveGoogle())->toBe('clave-navegador');
});

it('explica que falta la API key en lugar de mostrar el mapa', function () {
    config(['vehiculos.mapas.google_api_key' => null, 'vehiculos.mapas.google_js_api_key' => null]);

    Livewire::test(MapaEnVivo::class)
        ->assertSee('Falta la API key de Google Maps')
        ->assertDontSeeHtml('id="mapa-en-vivo"');
});
