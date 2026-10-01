<?php

use App\Mapas\RutasFalso;
use App\Mapas\RutasGoogle;
use App\Mapas\RutasOsrm;
use App\Mapas\ServicioRutas;
use App\Models\Usuario;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const OSRM = 'router.project-osrm.org/*';
const DIRECTIONS = 'maps.googleapis.com/maps/api/directions/*';
const RUTA_OK = '/api/ruta?origen_lat=-34.6037&origen_lng=-58.3816&destino_lat=-34.602&destino_lng=-58.38';

/** Recorrido: sale al este por Avenida de Mayo, dobla a la izquierda por Perú y llega. Coordenadas [lng, lat]. */
function respuestaOsrm(): array
{
    return [
        'code' => 'Ok',
        'routes' => [[
            'distance' => 340.6,
            'duration' => 61.4,
            'geometry' => ['type' => 'LineString', 'coordinates' => [
                [-58.3816, -34.6037], [-58.381, -34.6037], [-58.38, -34.6037], [-58.38, -34.603], [-58.38, -34.602],
            ]],
            'legs' => [['steps' => [
                ['distance' => 150.4, 'name' => 'Avenida de Mayo', 'maneuver' => ['type' => 'depart', 'bearing_after' => 90, 'location' => [-58.3816, -34.6037]]],
                ['distance' => 190.2, 'name' => 'Perú', 'maneuver' => ['type' => 'turn', 'modifier' => 'left', 'location' => [-58.38, -34.6037]]],
                ['distance' => 0, 'name' => 'Perú', 'maneuver' => ['type' => 'arrive', 'location' => [-58.38, -34.602]]],
            ]]],
        ]],
    ];
}

/** OSRM que no duerme de verdad: anota las esperas pedidas (en microsegundos). */
function osrmSinEspera(array &$esperas): RutasOsrm
{
    return new RutasOsrm('Demo/1.0 (prueba)', 'https://router.project-osrm.org', function (int $micro) use (&$esperas) {
        $esperas[] = $micro;
    });
}

/** Codifica puntos [lat, lng] con el algoritmo de polilíneas de Google. */
function polilinea(array $puntos): string
{
    $salida = '';
    $prev = [0, 0];
    foreach ($puntos as $p) {
        foreach ([0, 1] as $i) {
            $v = (int) round($p[$i] * 1e5);
            $d = $v - $prev[$i];
            $prev[$i] = $v;
            $d = $d < 0 ? ~($d << 1) : $d << 1;
            while ($d >= 0x20) {
                $salida .= chr((0x20 | ($d & 0x1F)) + 63);
                $d >>= 5;
            }
            $salida .= chr($d + 63);
        }
    }

    return $salida;
}

function respuestaDirections(): array
{
    return ['status' => 'OK', 'routes' => [['legs' => [[
        'distance' => ['value' => 341], 'duration' => ['value' => 75],
        'steps' => [
            [
                'html_instructions' => 'Dirígete al <b>este</b> por <b>Av. de Mayo</b> hacia <b>Perú</b>',
                'distance' => ['value' => 150], 'maneuver' => null,
                'start_location' => ['lat' => -34.6037, 'lng' => -58.3816], 'end_location' => ['lat' => -34.6037, 'lng' => -58.38],
                'polyline' => ['points' => polilinea([[-34.6037, -58.3816], [-34.6037, -58.381], [-34.6037, -58.38]])],
            ],
            [
                'html_instructions' => 'Gira a la <b>izquierda</b> en <b>Perú</b>&nbsp;<div style="font-size:0.9em">El destino está a la derecha.</div>',
                'distance' => ['value' => 191], 'maneuver' => 'turn-left',
                'start_location' => ['lat' => -34.6037, 'lng' => -58.38], 'end_location' => ['lat' => -34.602, 'lng' => -58.38],
                'polyline' => ['points' => polilinea([[-34.6037, -58.38], [-34.603, -58.38], [-34.602, -58.38]])],
            ],
        ],
    ]]]]];
}

it('devuelve el recorrido con la forma pedida (driver falso)', function () {
    $r = $this->actingAs(Usuario::factory()->create())->getJson(RUTA_OK)->assertOk();

    expect(array_keys($r->json()))->toEqualCanonicalizing(['distancia_m', 'duracion_s', 'puntos', 'pasos']);
    expect($r->json('distancia_m'))->toBeInt()->toBeGreaterThan(0)
        ->and($r->json('duracion_s'))->toBeInt()->toBeGreaterThan(0)
        ->and($r->json('puntos'))->toBe([[-34.6037, -58.3816], [-34.602, -58.38]])
        ->and($r->json('pasos'))->toHaveCount(2);
    expect(array_keys($r->json('pasos.0')))->toEqualCanonicalizing(['instruccion', 'distancia_m', 'indice', 'lat', 'lng', 'tipo']);
    expect($r->json('pasos.0.indice'))->toBe(0)->and($r->json('pasos.0.tipo'))->toBe('salida')
        ->and($r->json('pasos.1.indice'))->toBe(1)->and($r->json('pasos.1.tipo'))->toBe('llegada')
        ->and($r->json('pasos.1.instruccion'))->toBe('Llegaste a destino');
});

it('valida las coordenadas', function (string $consulta, string $campo) {
    $this->actingAs(Usuario::factory()->create())->getJson("/api/ruta?$consulta")
        ->assertUnprocessable()->assertJsonValidationErrors($campo);
})->with([
    ['origen_lng=-58.38&destino_lat=-34.6&destino_lng=-58.38', 'origen_lat'],
    ['origen_lat=-34.6&origen_lng=abc&destino_lat=-34.6&destino_lng=-58.38', 'origen_lng'],
    ['origen_lat=-34.6&origen_lng=-58.38&destino_lat=-91&destino_lng=-58.38', 'destino_lat'],
    ['origen_lat=-34.6&origen_lng=-58.38&destino_lat=-34.6&destino_lng=181', 'destino_lng'],
]);

it('exige sesión', function () {
    $this->getJson(RUTA_OK)->assertUnauthorized();
});

it('responde 200 con null si no hay recorrido', function () {
    config(['vehiculos.rutas.driver' => 'osrm']);
    Http::fake([OSRM => Http::response('error', 500)]);

    $r = $this->actingAs(Usuario::factory()->create())->getJson(RUTA_OK)->assertOk();

    expect($r->getContent())->toBe('null');
});

it('elige el driver por configuración', function () {
    config(['vehiculos.rutas.driver' => 'osrm']);
    expect(app(ServicioRutas::class))->toBeInstanceOf(RutasOsrm::class);

    config(['vehiculos.rutas.driver' => 'google', 'vehiculos.mapas.google_api_key' => 'k']);
    expect(app(ServicioRutas::class))->toBeInstanceOf(RutasGoogle::class);

    config(['vehiculos.rutas.driver' => 'falso']);
    expect(app(ServicioRutas::class))->toBeInstanceOf(RutasFalso::class);
});

it('OSRM pide con la URL, los parámetros y el User-Agent de la política y mapea puntos y pasos', function () {
    Http::fake([OSRM => Http::response(respuestaOsrm())]);
    $esperas = [];

    $r = osrmSinEspera($esperas)->ruta(-34.6037, -58.3816, -34.602, -58.38);

    expect($r)->toBe([
        'distancia_m' => 341,
        'duracion_s' => 61,
        'puntos' => [[-34.6037, -58.3816], [-34.6037, -58.381], [-34.6037, -58.38], [-34.603, -58.38], [-34.602, -58.38]],
        'pasos' => [
            ['instruccion' => 'Salí hacia el este por Avenida de Mayo', 'distancia_m' => 150, 'indice' => 0, 'lat' => -34.6037, 'lng' => -58.3816, 'tipo' => 'salida'],
            ['instruccion' => 'Doblá a la izquierda por Perú', 'distancia_m' => 190, 'indice' => 2, 'lat' => -34.6037, 'lng' => -58.38, 'tipo' => 'izquierda'],
            ['instruccion' => 'Llegaste a destino', 'distancia_m' => 0, 'indice' => 4, 'lat' => -34.602, 'lng' => -58.38, 'tipo' => 'llegada'],
        ],
    ]);
    Http::assertSent(function (Request $req) {
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return str_starts_with($req->url(), 'https://router.project-osrm.org/route/v1/driving/-58.3816,-34.6037;-58.38,-34.602?')
            && $req->header('User-Agent') === ['Demo/1.0 (prueba)']
            && $q === ['overview' => 'full', 'geometries' => 'geojson', 'steps' => 'true'];
    });
});

it('OSRM busca el índice de cada maniobra hacia adelante aunque el recorrido vuelva sobre sus pasos', function () {
    // Ida y vuelta: la llegada coincide con la salida, pero su índice es el último punto.
    $ida = [[-58.3816, -34.6037], [-58.381, -34.6037], [-58.38, -34.6037]];
    $coords = [...$ida, [-58.381, -34.60371], [-58.3816, -34.6037]];
    Http::fake([OSRM => Http::response(['code' => 'Ok', 'routes' => [[
        'distance' => 300, 'duration' => 60,
        'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
        'legs' => [['steps' => [
            ['distance' => 150, 'name' => '', 'maneuver' => ['type' => 'depart', 'location' => [-58.3816, -34.6037]]],
            ['distance' => 150, 'name' => '', 'maneuver' => ['type' => 'continue', 'modifier' => 'uturn', 'location' => [-58.37999, -34.6037]]],
            ['distance' => 0, 'name' => '', 'maneuver' => ['type' => 'arrive', 'location' => [-58.3816, -34.6037]]],
        ]]],
    ]]])]);
    $esperas = [];

    $pasos = osrmSinEspera($esperas)->ruta(-34.6037, -58.3816, -34.6037, -58.3816)['pasos'];

    expect(array_column($pasos, 'indice'))->toBe([0, 2, 4])
        ->and(array_column($pasos, 'instruccion'))->toBe(['Salí', 'Pegá la vuelta', 'Llegaste a destino']);
});

it('OSRM manda y cachea por las coordenadas redondeadas a 4 decimales durante 10 minutos', function () {
    Http::fake([OSRM => Http::response(respuestaOsrm())]);
    $esperas = [];
    $o = osrmSinEspera($esperas);

    $primera = $o->ruta(-34.603712, -58.381634, -34.602011, -58.380049);
    $segunda = $o->ruta(-34.603741, -58.381621, -34.601989, -58.379951);

    expect($segunda)->toBe($primera);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $req) => str_contains($req->url(), '/driving/-58.3816,-34.6037;-58.38,-34.602?'));

    $this->travel(11)->minutes();
    $o->ruta(-34.603712, -58.381634, -34.602011, -58.380049);
    Http::assertSentCount(2);
});

it('OSRM devuelve null ante error, no cachea el fallo y corta 60 s sin llamar', function () {
    Http::fake([OSRM => Http::sequence()->push('error', 500)->push(respuestaOsrm())]);
    $esperas = [];
    $o = osrmSinEspera($esperas);

    expect($o->ruta(-34.6037, -58.3816, -34.602, -58.38))->toBeNull();

    $esperas = [];
    expect($o->ruta(-34.61, -58.39, -34.62, -58.40))->toBeNull();
    expect($esperas)->toBe([]);
    Http::assertSentCount(1);

    $this->travel(61)->seconds();
    expect($o->ruta(-34.6037, -58.3816, -34.602, -58.38))->toBeArray();
    Http::assertSentCount(2);
});

it('OSRM corta también ante 429, sin conexión o una respuesta inesperada', function (string $falla) {
    $llamadas = 0;
    Http::fake([OSRM => function () use ($falla, &$llamadas) {
        $llamadas++;

        return match ($falla) {
            '429' => Http::response('demasiados', 429),
            'basura' => Http::response(['code' => 'Ok', 'routes' => [['legs' => 'x']]]),
            default => throw new ConnectionException('timeout'),
        };
    }]);
    $esperas = [];
    $o = osrmSinEspera($esperas);

    expect($o->ruta(-34.6037, -58.3816, -34.602, -58.38))->toBeNull();
    $o->ruta(-34.61, -58.39, -34.62, -58.40);

    expect($llamadas)->toBe(1);
})->with(['429', 'sin conexion', 'basura']);

it('OSRM sin ruta posible devuelve null sin cortar', function () {
    Http::fake([OSRM => Http::response(['code' => 'NoRoute', 'message' => 'Impossible route'], 400)]);
    $esperas = [];
    $o = osrmSinEspera($esperas);

    expect($o->ruta(-34.6037, -58.3816, -34.602, -58.38))->toBeNull();
    expect($o->ruta(-34.61, -58.39, -34.62, -58.40))->toBeNull();
    Http::assertSentCount(2);
});

it('OSRM usa 3 s de timeout', function () {
    $timeout = null;
    Http::fake([OSRM => function (Request $req, array $opciones) use (&$timeout) {
        $timeout = $opciones['timeout'] ?? null;

        return Http::response(respuestaOsrm());
    }]);
    $esperas = [];

    osrmSinEspera($esperas)->ruta(-34.6037, -58.3816, -34.602, -58.38);

    expect($timeout)->toEqual(3);
});

it('OSRM espera para respetar 1 pedido por segundo', function () {
    Http::fake([OSRM => Http::response(respuestaOsrm())]);
    $esperas = [];
    $o = osrmSinEspera($esperas);

    $o->ruta(-34.6037, -58.3816, -34.602, -58.38);
    expect($esperas)->toBe([]);

    $o->ruta(-34.61, -58.39, -34.62, -58.40);
    expect($esperas)->toHaveCount(1)->and($esperas[0])->toBeGreaterThan(0)->toBeLessThanOrEqual(1_000_000);
});

it('no deja en el log coordenadas ni la URL', function (string $driver) {
    Http::fake(['*' => fn (Request $req) => throw new ConnectionException('cURL error 28 for '.$req->url())]);
    Log::spy();
    $esperas = [];
    $servicio = $driver === 'osrm' ? osrmSinEspera($esperas) : new RutasGoogle('clave-secreta');

    $servicio->ruta(-34.61234, -58.38765, -34.65432, -58.45678);

    Log::shouldHaveReceived('warning')->withArgs(function (string $msg, array $ctx = []) {
        $todo = $msg.json_encode($ctx);

        return ! str_contains($todo, '34.6') && ! str_contains($todo, '58.') && ! str_contains($todo, 'http')
            && ! str_contains($todo, 'clave-secreta');
    })->once();
})->with(['osrm', 'google']);

it('Google usa Directions en castellano, une las polilíneas y deja las indicaciones sin HTML', function () {
    Http::fake([DIRECTIONS => Http::response(respuestaDirections())]);

    $r = (new RutasGoogle('clave'))->ruta(-34.603712, -58.381634, -34.602, -58.38);

    expect($r)->toBe([
        'distancia_m' => 341,
        'duracion_s' => 75,
        'puntos' => [[-34.6037, -58.3816], [-34.6037, -58.381], [-34.6037, -58.38], [-34.603, -58.38], [-34.602, -58.38]],
        'pasos' => [
            ['instruccion' => 'Dirígete al este por Av. de Mayo hacia Perú', 'distancia_m' => 150, 'indice' => 0, 'lat' => -34.6037, 'lng' => -58.3816, 'tipo' => 'salida'],
            ['instruccion' => 'Gira a la izquierda en Perú. El destino está a la derecha', 'distancia_m' => 191, 'indice' => 2, 'lat' => -34.6037, 'lng' => -58.38, 'tipo' => 'izquierda'],
            ['instruccion' => 'Llegaste a destino', 'distancia_m' => 0, 'indice' => 4, 'lat' => -34.602, 'lng' => -58.38, 'tipo' => 'llegada'],
        ],
    ]);
    Http::assertSent(function (Request $req) {
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return str_starts_with($req->url(), 'https://maps.googleapis.com/maps/api/directions/json?')
            && $q['origin'] === '-34.6037,-58.3816' && $q['destination'] === '-34.602,-58.38'
            && $q['mode'] === 'driving' && $q['language'] === 'es' && $q['key'] === 'clave';
    });
});

it('Google decodifica polilíneas reales', function () {
    $r = respuestaDirections();
    // Ejemplo de la documentación de Google: (38.5, -120.2), (40.7, -120.95), (43.252, -126.453).
    $r['routes'][0]['legs'][0]['steps'] = [[
        'html_instructions' => 'Dirígete al norte', 'distance' => ['value' => 1], 'maneuver' => null,
        'start_location' => ['lat' => 38.5, 'lng' => -120.2], 'end_location' => ['lat' => 43.252, 'lng' => -126.453],
        'polyline' => ['points' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'],
    ]];
    Http::fake([DIRECTIONS => Http::response($r)]);

    expect((new RutasGoogle('clave'))->ruta(38.5, -120.2, 43.252, -126.453)['puntos'])
        ->toBe([[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]]);
});

it('Google devuelve null sin resultados o ante error, y corta solo ante error', function () {
    Http::fake([DIRECTIONS => Http::sequence()
        ->push(['status' => 'ZERO_RESULTS', 'routes' => []])
        ->push(['status' => 'REQUEST_DENIED', 'error_message' => 'clave inválida'])
        ->push(respuestaDirections())]);
    $g = new RutasGoogle('clave');

    expect($g->ruta(-34.6037, -58.3816, -34.602, -58.38))->toBeNull();
    expect($g->ruta(-34.61, -58.39, -34.62, -58.40))->toBeNull();
    expect($g->ruta(-34.63, -58.41, -34.64, -58.42))->toBeNull();
    Http::assertSentCount(2);
});

it('limita /api/ruta a 60 pedidos por minuto por usuario, con mensaje en castellano', function () {
    $u = Usuario::factory()->create();
    $otro = Usuario::factory()->create();

    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($u)->getJson(RUTA_OK)->assertOk();
    }
    $this->actingAs($u)->getJson(RUTA_OK)
        ->assertStatus(429)
        ->assertJsonPath('message', 'Demasiados pedidos de recorrido. Probá de nuevo en un minuto.');

    $this->actingAs($otro)->getJson(RUTA_OK)->assertOk();

    $this->travel(61)->seconds();
    $this->actingAs($u)->getJson(RUTA_OK)->assertOk();
});
