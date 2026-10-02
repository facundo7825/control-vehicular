<?php

use App\Mapas\ServicioMapas;
use App\Mapas\ServicioMapasOsrm;
use App\Models\Viaje;
use App\Servicios\Asignador;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const OSRM_PUBLICO_TIEMPOS = 'router.project-osrm.org/*';

/** OSRM de tiempos que no duerme de verdad: anota las esperas pedidas (en microsegundos). */
function tiemposOsrm(array &$esperas, string $url = 'https://router.project-osrm.org'): ServicioMapasOsrm
{
    return new ServicioMapasOsrm('Demo/1.0 (prueba)', $url, function (int $micro) use (&$esperas) {
        $esperas[] = $micro;
    });
}

function tablaOsrm(array $segundos): array
{
    return ['code' => 'Ok', 'durations' => array_map(fn ($s) => [$s], $segundos)];
}

function rutaTiemposOsrm(float $segundos = 431.6): array
{
    return ['code' => 'Ok', 'routes' => [['distance' => 3012.4, 'duration' => $segundos, 'legs' => []]]];
}

it('MAPAS_DRIVER=osrm usa OSRM para los tiempos de viaje', function () {
    config(['vehiculos.mapas.driver' => 'osrm']);

    expect(app(ServicioMapas::class))->toBeInstanceOf(ServicioMapasOsrm::class);
});

it('duracionesHacia usa table con cada origen como fuente y el destino, y respeta las claves', function () {
    Http::fake([OSRM_PUBLICO_TIEMPOS => Http::response(tablaOsrm([120.4, null, 300.6]))]);
    $esperas = [];

    $r = tiemposOsrm($esperas)->duracionesHacia(
        [7 => [-34.6037123, -58.3816456], 'b' => [-34.7, -58.5], 9 => [-34.61, -58.39]],
        -34.65, -58.45,
    );

    expect($r)->toBe([7 => 120, 'b' => null, 9 => 301]);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $req) {
        $url = urldecode($req->url());

        return str_starts_with($url, 'https://router.project-osrm.org/table/v1/driving/-58.38165,-34.60371;-58.5,-34.7;-58.39,-34.61;-58.45,-34.65?')
            && $req['sources'] === '0;1;2' && (string) $req['destinations'] === '3' && $req['annotations'] === 'duration'
            && $req->hasHeader('User-Agent', 'Demo/1.0 (prueba)');
    });
});

it('duracionesHacia sin orígenes no consulta', function () {
    Http::fake();
    $esperas = [];

    expect(tiemposOsrm($esperas)->duracionesHacia([], -34.65, -58.45))->toBe([]);
    Http::assertNothingSent();
});

it('duracionRuta usa route sin geometría y la cachea 10 minutos', function () {
    Http::fake([OSRM_PUBLICO_TIEMPOS => Http::response(rutaTiemposOsrm(431.6))]);
    $esperas = [];
    $o = tiemposOsrm($esperas);

    expect($o->duracionRuta(-34.6037, -58.3816, -34.602, -58.38))->toBe(432);
    expect($o->duracionRuta(-34.6037, -58.3816, -34.602, -58.38))->toBe(432);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $req) => str_starts_with(urldecode($req->url()), 'https://router.project-osrm.org/route/v1/driving/-58.3816,-34.6037;-58.38,-34.602?')
        && $req['overview'] === 'false');

    $this->travel(11)->minutes();
    $o->duracionRuta(-34.6037, -58.3816, -34.602, -58.38);
    Http::assertSentCount(2);
});

it('sin ruta posible devuelve nulos sin cortar', function () {
    Http::fake([OSRM_PUBLICO_TIEMPOS => Http::response(['code' => 'NoRoute', 'message' => 'Impossible route'], 400)]);
    $esperas = [];
    $o = tiemposOsrm($esperas);

    expect($o->duracionRuta(-34.6037, -58.3816, -34.602, -58.38))->toBeNull();
    expect($o->duracionesHacia([1 => [-34.61, -58.39]], -34.62, -58.40))->toBe([1 => null]);
    Http::assertSentCount(2);
});

it('ante una falla devuelve nulos y corta 60 s sin llamar', function (string $falla) {
    $llamadas = 0;
    Http::fake([OSRM_PUBLICO_TIEMPOS => function () use ($falla, &$llamadas) {
        $llamadas++;
        if ($llamadas > 1) {
            return Http::response(tablaOsrm([60]));
        }

        return match ($falla) {
            '500' => Http::response('error', 500),
            '429' => Http::response('demasiados', 429),
            'code' => Http::response(['code' => 'InvalidQuery']),
            'filas de menos' => Http::response(tablaOsrm([60])),
            'basura' => Http::response(['code' => 'Ok', 'durations' => 'x']),
            default => throw new ConnectionException('timeout'),
        };
    }]);
    $esperas = [];
    $o = tiemposOsrm($esperas);

    expect($o->duracionesHacia([1 => [-34.61, -58.39], 2 => [-34.6, -58.4]], -34.62, -58.40))->toBe([1 => null, 2 => null]);
    expect($o->duracionesHacia([1 => [-34.61, -58.39]], -34.62, -58.40))->toBe([1 => null]);
    expect($o->duracionRuta(-34.6037, -58.3816, -34.602, -58.38))->toBeNull();
    expect($llamadas)->toBe(1);

    $this->travel(61)->seconds();
    expect($o->duracionesHacia([1 => [-34.61, -58.39]], -34.62, -58.40))->toBe([1 => 60]);
    expect($llamadas)->toBe(2);
})->with(['500', '429', 'code', 'filas de menos', 'basura', 'sin conexion']);

it('usa 3 s de timeout', function () {
    $timeout = null;
    Http::fake([OSRM_PUBLICO_TIEMPOS => function (Request $req, array $opciones) use (&$timeout) {
        $timeout = $opciones['timeout'] ?? null;

        return Http::response(tablaOsrm([60]));
    }]);
    $esperas = [];

    tiemposOsrm($esperas)->duracionesHacia([1 => [-34.61, -58.39]], -34.62, -58.40);

    expect($timeout)->toEqual(3);
});

it('con el servidor público espera para respetar 1 pedido por segundo, compartido con los recorridos', function () {
    Http::fake([OSRM_PUBLICO_TIEMPOS => Http::response(tablaOsrm([60]))]);
    $esperas = [];
    $o = tiemposOsrm($esperas);

    $o->duracionesHacia([1 => [-34.61, -58.39]], -34.62, -58.40);
    expect($esperas)->toBe([]);

    $o->duracionesHacia([1 => [-34.61, -58.39]], -34.62, -58.40);
    expect($esperas)->toHaveCount(1)->and($esperas[0])->toBeGreaterThan(0)->toBeLessThanOrEqual(1_000_000);
});

it('con el servidor público y el lock tomado devuelve nulos sin cortar', function () {
    Http::fake([OSRM_PUBLICO_TIEMPOS => Http::response(tablaOsrm([60]))]);
    $lock = Cache::lock('rutas:osrm:lock', 10);
    expect($lock->get())->toBeTrue();
    $esperas = [];
    $o = tiemposOsrm($esperas);

    expect($o->duracionesHacia([1 => [-34.61, -58.39]], -34.62, -58.40))->toBe([1 => null]);
    Http::assertSentCount(0);

    $lock->release();
    expect($o->duracionesHacia([1 => [-34.61, -58.39]], -34.62, -58.40))->toBe([1 => 60]);
});

it('con un OSRM propio no espera ni usa el lock', function () {
    Http::fake(['osrm.interno.example:5000/*' => Http::response(tablaOsrm([60]))]);
    $lock = Cache::lock('rutas:osrm:lock', 10);
    expect($lock->get())->toBeTrue();
    $esperas = [];
    $o = tiemposOsrm($esperas, 'http://osrm.interno.example:5000/');

    expect($o->duracionesHacia([1 => [-34.61, -58.39]], -34.62, -58.40))->toBe([1 => 60]);
    expect($o->duracionesHacia([1 => [-34.61, -58.39]], -34.62, -58.40))->toBe([1 => 60]);

    expect($esperas)->toBe([]);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $req) => str_starts_with($req->url(), 'http://osrm.interno.example:5000/table/v1/driving/'));
    $lock->release();
});

it('no deja en el log coordenadas ni la URL', function (string $metodo) {
    Http::fake(['*' => fn (Request $req) => throw new ConnectionException('cURL error 28 for '.$req->url())]);
    Log::spy();
    $esperas = [];
    $o = tiemposOsrm($esperas);

    $metodo === 'tabla'
        ? $o->duracionesHacia([1 => [-34.61234, -58.38765]], -34.65432, -58.45678)
        : $o->duracionRuta(-34.61234, -58.38765, -34.65432, -58.45678);

    Log::shouldHaveReceived('warning')->withArgs(function (string $msg, array $ctx = []) {
        $todo = $msg.json_encode($ctx);

        return ! str_contains($todo, '34.6') && ! str_contains($todo, '58.') && ! str_contains($todo, 'http');
    })->once();
})->with(['tabla', 'ruta']);

it('el asignador conserva el orden por distancia si OSRM no responde', function () {
    config(['vehiculos.mapas.driver' => 'osrm', 'vehiculos.rutas.osrm_url' => 'http://osrm.interno.example:5000']);
    Http::fake(['osrm.interno.example:5000/*' => Http::response('error', 500)]);
    $lejos = choferEnTurno(-34.70, -58.50);
    $cerca = choferEnTurno(-34.601, -58.381);
    $viaje = Viaje::factory()->create(['origen_lat' => -34.60, 'origen_lng' => -58.38]);

    $orden = app(Asignador::class)
        ->ordenarPorCercania($viaje, collect([$lejos->load('ubicacion'), $cerca->load('ubicacion')]));

    expect($orden->pluck('id')->all())->toBe([$cerca->id, $lejos->id]);
});
