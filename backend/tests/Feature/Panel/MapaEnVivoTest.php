<?php

use App\Enums\EstadoViaje;
use App\Excepciones\ReglaNegocio;
use App\Filament\Pages\MapaEnVivo;
use App\Filament\Resources\Usuarios\UsuarioResource;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Mapas\Distancia;
use App\Mapas\ServicioRutas;
use App\Models\PuntoRecorrido;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\DatosMapaPanel;
use App\Servicios\EstimadorLlegada;
use App\Servicios\KilometrosRecorridos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
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
        'url' => ViajeResource::getUrl('view', ['record' => $viaje->id]),
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

/** Un viaje finalizado de $chofer en $finalizadoEn (UTC) con su recorrido real ($puntos como [lat, lng]). */
function viajeFinalizadoCon(Usuario $chofer, string $finalizadoEn, array $puntos = []): Viaje
{
    $viaje = Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Finalizado, 'finalizado_en' => $finalizadoEn,
    ]);
    // Se cargan al revés: el cálculo tiene que ordenarlos por registrado_en.
    foreach (array_reverse($puntos, true) as $i => [$lat, $lng]) {
        PuntoRecorrido::create([
            'viaje_id' => $viaje->id, 'lat' => $lat, 'lng' => $lng,
            'registrado_en' => Carbon::parse($finalizadoEn)->subMinutes(30)->addMinutes($i),
        ]);
    }
    // Como al finalizar de verdad (MaquinaEstadosViaje): los metros quedan guardados en el viaje.
    $viaje->update(['metros_recorridos' => KilometrosRecorridos::metrosDe($viaje->id)]);

    return $viaje;
}

/** Lo que devuelve EstimadorLlegada::estimar con $segundos. */
function estimacion(?int $segundos): array
{
    return [
        'hacia' => 'origen', 'segundos' => $segundos, 'metros' => $segundos === null ? null : 3000,
        'calculado_en' => now()->toIso8601String(), 'ubicacion_actualizada_en' => now()->toIso8601String(),
    ];
}

it('el chofer con viaje activo trae el número, el solicitante, hacia dónde va, la llegada estimada y el enlace', function () {
    $this->mock(EstimadorLlegada::class)->shouldReceive('estimar')->andReturn(estimacion(400));
    $chofer = choferEnTurno(-34.61, -58.39);
    $solicitante = Usuario::factory()->create(['nombre' => 'Ana Solicitante']);
    $viaje = Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'solicitante_id' => $solicitante->id, 'estado' => EstadoViaje::EnCamino,
        'origen_direccion' => 'Tribunales', 'destino_direccion' => 'Casa de Gobierno',
    ]);

    $datos = app(DatosMapaPanel::class)->obtener()['choferes'][0];

    expect($datos['viaje'])->toBe([
        'id' => $viaje->id,
        'estado' => 'en_camino',
        'estado_etiqueta' => 'En camino',
        'solicitante' => 'Ana Solicitante',
        'hacia' => 'origen',
        'hacia_direccion' => 'Tribunales',
        'llega_en_min' => 7, // 400 s, redondeado hacia arriba
        'url' => ViajeResource::getUrl('view', ['record' => $viaje->id]),
    ]);
    expect($datos['url'])->toBe(UsuarioResource::getUrl('edit', ['record' => $chofer->id]));
});

it('en curso va hacia el destino y sin señal queda sin estimación', function () {
    $chofer = choferEnTurno(minutos: 75); // la última ubicación es vieja: el estimador no da segundos
    Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::EnCurso, 'destino_direccion' => 'Casa de Gobierno',
    ]);

    $viaje = app(DatosMapaPanel::class)->obtener()['choferes'][0]['viaje'];

    expect($viaje)->toMatchArray(['hacia' => 'destino', 'hacia_direccion' => 'Casa de Gobierno', 'llega_en_min' => null]);
});

it('el chofer sin viaje activo trae viaje null', function () {
    $chofer = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()]);

    expect(app(DatosMapaPanel::class)->obtener()['choferes'][0]['viaje'])->toBeNull();
});

it('si el estimador falla el viaje queda sin estimación y el mapa sigue andando', function (Throwable $error, bool $seReporta) {
    Exceptions::fake();
    $this->mock(EstimadorLlegada::class)->shouldReceive('estimar')->andThrow($error);
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);

    $datos = app(DatosMapaPanel::class)->obtener()['choferes'][0]['viaje'];

    expect($datos)->toMatchArray(['id' => $viaje->id, 'hacia' => 'origen', 'llega_en_min' => null]);
    // La regla de negocio es esperable (el viaje cambió de estado); una falla del servicio se reporta.
    $seReporta ? Exceptions::assertReported(RuntimeException::class) : Exceptions::assertNothingReported();
})->with([
    'regla de negocio' => fn () => [new ReglaNegocio('El viaje no tiene un chofer en camino.'), false],
    'servicio de mapas caído' => fn () => [new RuntimeException('timeout'), true],
]);

it('hoy trae los viajes finalizados en el día local, los km recorridos y desde qué hora está en turno', function () {
    // Ahora: 01/10 12:00 UTC = 09:00 en Buenos Aires; el día local empezó a las 03:00 UTC.
    $chofer = choferEnTurno();
    $chofer->turnoAbierto->update(['inicio' => '2026-10-01 10:30:00']);
    $tramo1 = [[-34.600, -58.380], [-34.605, -58.380], [-34.610, -58.385]];
    $tramo2 = [[-34.620, -58.400], [-34.630, -58.400]];
    viajeFinalizadoCon($chofer, '2026-10-01 04:00:00', $tramo1);
    viajeFinalizadoCon($chofer, '2026-10-01 11:00:00', $tramo2);
    viajeFinalizadoCon($chofer, '2026-10-01 02:00:00', [[-34.0, -58.0], [-35.0, -58.0]]); // 30/09 23:00 local: ayer
    viajeFinalizadoCon(choferEnTurno(), '2026-10-01 05:00:00', [[-34.0, -58.0], [-35.0, -58.0]]); // de otro chofer
    Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Cancelado, 'cancelado_en' => now()]);

    $metros = Distancia::metros(...$tramo1[0], ...$tramo1[1]) + Distancia::metros(...$tramo1[1], ...$tramo1[2])
        + Distancia::metros(...$tramo2[0], ...$tramo2[1]);

    $choferes = collect(app(DatosMapaPanel::class)->obtener()['choferes'])->keyBy('id');

    expect(round($metros / 1000, 1))->toBe(2.4)
        ->and($choferes[$chofer->id]['hoy'])->toBe(['viajes' => 2, 'km' => 2.4, 'turno_desde' => '07:30']);
});

it('si el turno empezó un día anterior muestra también la fecha', function () {
    $chofer = choferEnTurno();
    $chofer->turnoAbierto->update(['inicio' => '2026-10-01 01:00:00']); // 30/09 22:00 en Buenos Aires

    expect(app(DatosMapaPanel::class)->obtener()['choferes'][0]['hoy']['turno_desde'])->toBe('30/09 22:00');
});

it('sin viajes hoy trae ceros', function () {
    choferEnTurno(); // turno abierto hace 2 h: 10:00 UTC = 07:00 local

    expect(app(DatosMapaPanel::class)->obtener()['choferes'][0]['hoy'])->toBe(['viajes' => 0, 'km' => 0.0, 'turno_desde' => '07:00']);
});

it('arma los datos con la misma cantidad de consultas para 1 chofer que para 5', function () {
    // El estimador consulta por viaje (cacheado 30 s): se aísla para contar solo las consultas del armado.
    $this->mock(EstimadorLlegada::class)->shouldReceive('estimar')->andReturn(estimacion(60));
    $sembrar = function (): void {
        $chofer = choferEnTurno();
        Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::EnCamino]);
        viajeFinalizadoCon($chofer, '2026-10-01 11:00:00', [[-34.60, -58.38], [-34.61, -58.38]]);
        reservaAceptada(choferEnTurno(), now()->addMinutes(20)); // otro con una reserva próxima, sin viaje activo
    };
    $contar = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(DatosMapaPanel::class)->obtener();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $sembrar();
    $conUno = $contar();
    foreach (range(1, 4) as $i) {
        $sembrar();
    }
    $conCinco = $contar();

    expect(app(DatosMapaPanel::class)->obtener()['choferes'])->toHaveCount(10)
        ->and($conCinco)->toBe($conUno);
});

it('el script resalta el viaje tocado y el globo del chofer enlaza al viaje y al chofer', function (?string $clave) {
    config(['vehiculos.mapas.google_api_key' => $clave, 'vehiculos.mapas.google_js_api_key' => null]);
    choferEnTurno();

    // Livewire incrusta el @script escapado en HTML.
    $html = html_entity_decode($this->get(MapaEnVivo::getUrl())->assertOk()->getContent(), ENT_QUOTES);

    expect($html)
        ->toContain('const nivelResaltado')
        ->toContain('const ajustarEstilo')
        ->toContain('const resaltadoVigente')
        ->toContain('const textoLlegada')
        ->toContain('data-resaltar-viaje')
        ->toContain('Ver recorrido')
        ->toContain('Ver viaje')
        ->toContain('escapar(c.viaje.url)')
        ->toContain('escapar(c.url)')
        ->toContain('Hoy:')
        // Tocar el mapa vacío o Escape quitan el resaltado; el listener de teclado no se acumula.
        ->toContain('quitarResaltado')
        ->toContain("removeEventListener('keydown', quitarConEscape)");
})->with(['leaflet' => [null], 'google' => ['clave']]);

it('con Google el resaltado encuadra con margen y zoom acotado, y el globo del viaje sigue a los datos', function () {
    config(['vehiculos.mapas.google_api_key' => 'clave', 'vehiculos.mapas.google_js_api_key' => null]);
    choferEnTurno();

    $html = html_entity_decode($this->get(MapaEnVivo::getUrl())->assertOk()->getContent(), ENT_QUOTES);

    expect($html)
        ->toContain('mapa.fitBounds(d.limites, MARGEN_ENCUADRE)')
        ->toContain("google.maps.event.addListenerOnce(mapa, 'idle'")
        // La acotación del zoom espera solo al encuadre recién pedido: se descarta la anterior y vence sola.
        ->toContain('cancelarAcotarZoom?.()')
        ->toContain('PLAZO_ACOTAR_ZOOM_MS')
        ->toContain('ZOOM_MAXIMO_ENCUADRE')
        // El globo de un viaje se refresca en cada actualización y se cierra si el viaje ya no está activo.
        ->toContain('viajeConGlobo');
});

it('los km de hoy salen de los metros guardados en cada viaje, sin leer el recorrido en cada consulta', function () {
    $chofer = choferEnTurno();
    Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Finalizado, 'finalizado_en' => '2026-10-01 11:00:00',
        'metros_recorridos' => 5250,
    ]);
    Viaje::factory()->create([ // de antes de guardar los metros y sin recorrido: suma 0
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Finalizado, 'finalizado_en' => '2026-10-01 11:30:00',
    ]);

    DB::enableQueryLog();
    $choferes = collect(app(DatosMapaPanel::class)->obtener()['choferes'])->keyBy('id');
    $consultas = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($choferes[$chofer->id]['hoy']['viajes'])->toBe(2)
        ->and($choferes[$chofer->id]['hoy']['km'])->toBe(5.3)
        ->and($consultas->filter(fn (string $sql) => str_contains($sql, 'recorrido_viaje')))->toBeEmpty();
});
