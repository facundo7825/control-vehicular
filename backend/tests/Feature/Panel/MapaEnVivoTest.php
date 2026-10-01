<?php

use App\Enums\EstadoViaje;
use App\Filament\Pages\MapaEnVivo;
use App\Mapas\ServicioRutas;
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
        'recorrido' => app(ServicioRutas::class)->ruta(-34.6037, -58.3816, -34.609, -58.392)['puntos'],
    ]]);
    expect($datos['viajes'][0]['recorrido'])->not->toBeEmpty();
});

it('sin recorrido disponible el viaje viene con recorrido null (el mapa traza una recta)', function () {
    $this->mock(ServicioRutas::class)->shouldReceive('ruta')->andReturnNull();
    Viaje::factory()->create(['chofer_id' => choferEnTurno()->id, 'estado' => EstadoViaje::EnCurso]);

    expect(app(DatosMapaPanel::class)->obtener()['viajes'][0]['recorrido'])->toBeNull();
});

it('el script dibuja el recorrido por calles y, sin recorrido, una recta punteada', function (?string $clave) {
    config(['vehiculos.mapas.google_api_key' => $clave, 'vehiculos.mapas.google_js_api_key' => $clave]);

    $html = Livewire::test(MapaEnVivo::class)->assertOk()->html();

    expect($html)->toContain('v.recorrido');
    expect($html)->toContain('ESTILO_RECTA');
})->with(['leaflet' => [null], 'google' => ['clave']]);

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

it('el script del mapa escucha los datos antes de cargar la librería y avisa si no se pudo cargar', function (?string $clave) {
    config(['vehiculos.mapas.google_api_key' => $clave, 'vehiculos.mapas.google_js_api_key' => null]);
    choferEnTurno();

    // Livewire incrusta el @script escapado en HTML.
    $html = html_entity_decode($this->get(MapaEnVivo::getUrl())->assertOk()->getContent(), ENT_QUOTES);

    // El listener se registra antes de crear el <script> de la librería y guarda el último dato.
    $listener = strpos($html, "\$wire.\$on('mapa-datos'");
    expect($listener)->not->toBeFalse()
        ->and(substr_count($html, "\$wire.\$on('mapa-datos'"))->toBe(1)
        ->and($listener)->toBeLessThan(strpos($html, "document.createElement('script')"));
    expect($html)->toContain("addEventListener('error'")
        ->toContain('No se pudo cargar el mapa');
})->with(['leaflet' => [null], 'google' => ['clave']]);

it('el tooltip de la línea del viaje se crea una vez y en cada refresco solo cambia el texto', function () {
    config(['vehiculos.mapas.google_api_key' => null, 'vehiculos.mapas.google_js_api_key' => null]);
    choferEnTurno();

    // Livewire incrusta el @script escapado en HTML.
    $html = html_entity_decode($this->get(MapaEnVivo::getUrl())->assertOk()->getContent(), ENT_QUOTES);

    expect($html)->toContain('capas.linea.setTooltipContent(')
        ->not->toContain('capas.linea.bindTooltip(');
});

it('muestra el buscador de choferes arriba del mapa, fuera del alcance del polling', function (?string $clave) {
    config(['vehiculos.mapas.google_api_key' => $clave, 'vehiculos.mapas.google_js_api_key' => null]);
    choferEnTurno();

    $html = Livewire::test(MapaEnVivo::class)->assertOk()
        ->assertSee('Buscar chofer (nombre o patente)')
        ->html();

    // El buscador vive en su propio bloque wire:ignore (el polling no le borra el texto ni la lista) y va antes del mapa.
    expect($html)->toMatch('/<div[^>]*id="buscador-choferes"[^>]*wire:ignore|<div[^>]*wire:ignore[^>]*id="buscador-choferes"/')
        ->and(strpos($html, 'id="buscador-choferes"'))->toBeLessThan(strpos($html, 'id="mapa-en-vivo"'));
})->with(['leaflet' => [null], 'google' => ['clave']]);

it('el script filtra sin distinguir acentos y dibuja a los choferes como autos y al origen como un punto', function (?string $clave) {
    config(['vehiculos.mapas.google_api_key' => $clave, 'vehiculos.mapas.google_js_api_key' => null]);
    choferEnTurno();

    // Livewire incrusta el @script escapado en HTML.
    $html = html_entity_decode($this->get(MapaEnVivo::getUrl())->assertOk()->getContent(), ENT_QUOTES);

    expect($html)
        // Filtro: normalización sin acentos ni mayúsculas, hasta 8 coincidencias y atenuado de los que no coinciden.
        ->toContain("normalize('NFD')")
        ->toContain('const buscarChoferes')
        ->toContain('setOpacity(')
        ->toContain('<em>sin ubicaci') // "sin ubicación todavía": Livewire escapa los acentos del script
        ->toContain("'Enter'")
        ->toContain("'Escape'")
        // Íconos: auto (Material directions_car), punto naranja para el origen y pin rojo para el destino.
        ->toContain('const svgAuto')
        ->toContain('M18.92 6.01C18.72 5.42')
        ->toContain('const svgPunto')
        ->toContain('const svgPin')
        ->not->toContain('L.circleMarker(')
        ->not->toContain("letra('O')")
        // Enter elige con los datos al día el primero con ubicación; la lista usa estilos propios (sin el
        // tope de ancho ni el corte de texto del dropdown de Filament); el listener de clic no se acumula.
        ->toContain('const primeraConUbicacion')
        ->toContain('primeraConUbicacion(ultimosDatos.choferes, campo.value)')
        ->toContain('mapa-en-vivo-lista')
        ->toContain("removeEventListener('click', cerrarAlClicFuera)")
        // La sombra de Leaflet va por CSS: ningún id de filtro repetido en cada ícono del documento.
        ->toContain('mapa-en-vivo-icono')
        ->not->toContain('sombra-mapa-en-vivo');

    // El rango de acentos se escribe con escapes, no con los caracteres combinantes literales.
    $vista = file_get_contents(resource_path('views/filament/pages/mapa-en-vivo.blade.php'));
    expect($vista)->toContain('/[\u0300-\u036f]/g')
        ->and(preg_match('/[\x{0300}-\x{036f}]/u', $vista))->toBe(0);
    // La vista no usa las clases del dropdown de Filament (las del resto del panel no cuentan).
    expect($vista)->not->toContain('fi-dropdown');
})->with(['leaflet' => [null], 'google' => ['clave']]);

it('los datos del mapa traen nombre y patente de todos los choferes en turno, también de los sin ubicación', function () {
    $conUbicacion = choferEnTurno();
    $sinUbicacion = Turno::factory()->create()->chofer;

    Livewire::test(MapaEnVivo::class)
        ->call('refrescar')
        ->assertDispatched('mapa-datos', function (string $evento, array $parametros) use ($conUbicacion, $sinUbicacion) {
            $choferes = collect($parametros['datos']['choferes'])->keyBy('id');

            return $choferes->count() === 2
                && $choferes[$conUbicacion->id]['nombre'] === $conUbicacion->nombre
                && $choferes[$conUbicacion->id]['patente'] === $conUbicacion->turnoAbierto->vehiculo->patente
                && $choferes[$sinUbicacion->id]['nombre'] === $sinUbicacion->nombre
                && $choferes[$sinUbicacion->id]['patente'] === $sinUbicacion->turnoAbierto->vehiculo->patente
                && $choferes[$sinUbicacion->id]['lat'] === null;
        });
});
