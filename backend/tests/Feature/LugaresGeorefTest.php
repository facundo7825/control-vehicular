<?php

use App\Mapas\BuscadorCombinado;
use App\Mapas\BuscadorGeoref;
use App\Mapas\BuscadorLugares;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const GEOREF = 'apis.datos.gob.ar/georef/api/*';

function direccionGeoref(string $calle, ?string $cruce, string $localidad, ?float $lat, ?float $lon, ?int $altura = null): array
{
    return [
        'altura' => ['valor' => $altura],
        'calle' => ['nombre' => $calle],
        'calle_cruce_1' => ['nombre' => $cruce],
        'departamento' => ['nombre' => mb_strtoupper($localidad)],
        'localidad_censal' => ['nombre' => $localidad],
        'nomenclatura' => $calle.($cruce ? " Y $cruce" : '').", $localidad, Catamarca",
        'provincia' => ['nombre' => 'Catamarca'],
        'ubicacion' => ['lat' => $lat, 'lon' => $lon],
    ];
}

function respuestaGeoref(array $direcciones): array
{
    return ['cantidad' => count($direcciones), 'direcciones' => $direcciones];
}

function georef(?string $provincia = 'Catamarca'): BuscadorGeoref
{
    return new BuscadorGeoref('https://apis.datos.gob.ar/georef/api', $provincia);
}

/** Buscador de prueba que devuelve siempre lo mismo y cuenta las llamadas. */
function buscadorFijo(array $lugares, int &$llamadas): BuscadorLugares
{
    return new class($lugares, $llamadas) implements BuscadorLugares
    {
        public function __construct(private array $lugares, private int &$llamadas) {}

        public function buscar(string $texto, ?float $lat, ?float $lng): array
        {
            $this->llamadas++;

            return $this->lugares;
        }
    };
}

function lugar(string $nombre, float $lat, float $lng): array
{
    return ['nombre' => $nombre, 'direccion' => "$nombre, Catamarca", 'lat' => $lat, 'lng' => $lng];
}

it('Georef pide direcciones con la provincia y mapea con nombres legibles', function () {
    Http::fake([GEOREF => Http::response(respuestaGeoref([
        direccionGeoref('SARMIENTO', 'RIVADAVIA', 'Belén', -27.6496, -67.0310),
        direccionGeoref('AV. PTE. JUAN DOMINGO PERON', null, 'San Fernando del Valle de Catamarca', -28.4696, -65.7795, 600),
    ]))]);

    $r = georef()->buscar('Sarmiento y Rivadavia', null, null);

    expect($r)->toBe([
        ['nombre' => 'Sarmiento y Rivadavia, Belén', 'direccion' => 'Sarmiento y Rivadavia, Belén, Catamarca', 'lat' => -27.6496, 'lng' => -67.031],
        ['nombre' => 'Av. Pte. Juan Domingo Peron 600, San Fernando del Valle de Catamarca', 'direccion' => 'Av. Pte. Juan Domingo Peron, San Fernando del Valle de Catamarca, Catamarca', 'lat' => -28.4696, 'lng' => -65.7795],
    ]);
    Http::assertSent(function (Request $req) {
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return str_starts_with($req->url(), 'https://apis.datos.gob.ar/georef/api/direcciones?')
            && $q['direccion'] === 'Sarmiento y Rivadavia' && $q['provincia'] === 'Catamarca'
            && ! isset($q['lat']) && ! isset($q['lon']);
    });
});

it('Georef respeta los números romanos y los conectores en minúscula', function () {
    Http::fake([GEOREF => Http::response(respuestaGeoref([
        direccionGeoref('JUAN XXIII', 'AVENIDA DE LOS INMIGRANTES', 'Andalgalá', -27.58, -66.31),
    ]))]);

    expect(georef()->buscar('juan xxiii', null, null)[0]['nombre'])->toBe('Juan XXIII y Avenida de los Inmigrantes, Andalgalá');
});

it('Georef descarta las direcciones sin ubicación y devuelve como mucho 5', function () {
    $filas = [direccionGeoref('SARMIENTO', 'RIVADAVIA', 'Belén', null, null)];
    for ($i = 0; $i < 8; $i++) {
        $filas[] = direccionGeoref('SARMIENTO', 'RIVADAVIA', "Pueblo $i", -27.0 - $i, -66.0);
    }
    Http::fake([GEOREF => Http::response(respuestaGeoref($filas))]);

    $r = georef()->buscar('Sarmiento y Rivadavia', null, null);

    expect($r)->toHaveCount(5)->and($r[0]['nombre'])->toBe('Sarmiento y Rivadavia, Pueblo 0');
});

it('Georef ordena por cercanía a la ubicación sin enviarla', function () {
    Http::fake([GEOREF => Http::response(respuestaGeoref([
        direccionGeoref('SARMIENTO', 'RIVADAVIA', 'Belén', -27.6496, -67.0310),
        direccionGeoref('SARMIENTO', 'RIVADAVIA', 'San Fernando del Valle de Catamarca', -28.4696, -65.7795),
        direccionGeoref('SARMIENTO', 'RIVADAVIA', 'Andalgalá', -27.58, -66.31),
    ]))]);

    $r = georef()->buscar('Sarmiento y Rivadavia', -28.47, -65.78);

    expect(array_column($r, 'nombre'))->toBe([
        'Sarmiento y Rivadavia, San Fernando del Valle de Catamarca',
        'Sarmiento y Rivadavia, Andalgalá',
        'Sarmiento y Rivadavia, Belén',
    ]);
    Http::assertSent(fn (Request $req) => ! str_contains($req->url(), '28.4') && ! str_contains($req->url(), '65.7'));
});

it('Georef sin provincia configurada no la manda', function () {
    Http::fake([GEOREF => Http::response(respuestaGeoref([]))]);

    georef(null)->buscar('Sarmiento y Rivadavia', null, null);

    Http::assertSent(fn (Request $req) => ! str_contains($req->url(), 'provincia'));
});

it('Georef usa la URL configurada', function () {
    Http::fake(['georef.local/*' => Http::response(respuestaGeoref([]))]);

    (new BuscadorGeoref('http://georef.local/api/', 'Catamarca'))->buscar('Sarmiento', null, null);

    Http::assertSent(fn (Request $req) => str_starts_with($req->url(), 'http://georef.local/api/direcciones?'));
});

it('Georef usa 3 s de timeout', function () {
    $timeout = null;
    Http::fake([GEOREF => function (Request $req, array $opciones) use (&$timeout) {
        $timeout = $opciones['timeout'] ?? null;

        return Http::response(respuestaGeoref([]));
    }]);

    georef()->buscar('Sarmiento', null, null);

    expect($timeout)->toEqual(3);
});

it('Georef cachea 24 h por consulta normalizada, también con otra ubicación', function () {
    Http::fake([GEOREF => Http::response(respuestaGeoref([direccionGeoref('SARMIENTO', 'RIVADAVIA', 'Belén', -27.6496, -67.031)]))]);
    $b = georef();

    $primera = $b->buscar('  Sarmiento y Rivadavia ', null, null);
    expect($b->buscar('sarmiento y rivadavia', -28.4, -65.7))->toBe($primera);
    Http::assertSentCount(1);

    $this->travel(25)->hours();
    $b->buscar('sarmiento y rivadavia', null, null);
    Http::assertSentCount(2);
});

it('Georef devuelve [] ante error, no cachea el fallo y corta 60 s sin llamar', function () {
    Http::fake([GEOREF => Http::sequence()
        ->push('error', 500)
        ->push(respuestaGeoref([direccionGeoref('SARMIENTO', 'RIVADAVIA', 'Belén', -27.6496, -67.031)]))]);
    $b = georef();

    expect($b->buscar('sarmiento', null, null))->toBe([]);
    expect($b->buscar('rivadavia', null, null))->toBe([]);
    Http::assertSentCount(1);

    $this->travel(61)->seconds();
    expect($b->buscar('sarmiento', null, null))->toHaveCount(1);
    Http::assertSentCount(2);
});

it('Georef corta también sin conexión y ante una respuesta inválida', function (string $falla) {
    $llamadas = 0;
    Http::fake([GEOREF => function () use ($falla, &$llamadas) {
        $llamadas++;

        return $falla === 'invalida' ? Http::response('<html>', 200) : throw new ConnectionException('timeout');
    }]);
    $b = georef();

    expect($b->buscar('sarmiento', null, null))->toBe([]);
    expect($b->buscar('rivadavia', null, null))->toBe([]);
    expect($llamadas)->toBe(1);
})->with(['sin conexion', 'invalida']);

it('Georef no deja en el log el texto buscado ni la URL', function () {
    Http::fake([GEOREF => fn (Request $req) => throw new ConnectionException('cURL error 28 for '.$req->url())]);
    Log::spy();

    georef()->buscar('calle secreta 123', -28.46912, -65.77954);

    Log::shouldHaveReceived('warning')->withArgs(function (string $msg, array $ctx = []) {
        $todo = $msg.json_encode($ctx);

        return ! str_contains($todo, 'secreta') && ! str_contains($todo, 'datos.gob.ar') && ! str_contains($todo, '28.4');
    })->once();
});

it('el combinado consulta en orden, une, quita duplicados de menos de 30 m y devuelve como mucho 5', function () {
    $a = $b = 0;
    $primero = buscadorFijo([lugar('Plaza 25 de Mayo', -28.4686, -65.7791), lugar('Catedral', -28.4689, -65.7785)], $a);
    $segundo = buscadorFijo([
        lugar('Plaza (Georef)', -28.4687, -65.7792), // ~15 m de la plaza: duplicado
        lugar('Terminal', -28.4740, -65.7860),
        lugar('Hospital', -28.4630, -65.7750),
        lugar('Casa de Gobierno', -28.4680, -65.7800),
        lugar('Aeropuerto', -28.5930, -65.7510),
    ], $b);

    $r = (new BuscadorCombinado([$primero, $segundo]))->buscar('plaza', -28.47, -65.78);

    expect(array_column($r, 'nombre'))->toBe(['Plaza 25 de Mayo', 'Catedral', 'Terminal', 'Hospital', 'Casa de Gobierno']);
    expect([$a, $b])->toBe([1, 1]);
});

it('el combinado no consulta los siguientes si ya tiene 5', function () {
    $a = $b = 0;
    $primero = buscadorFijo(array_map(fn (int $i) => lugar("L$i", -28.0 - $i, -65.0), range(1, 5)), $a);
    $segundo = buscadorFijo([lugar('Otro', -29.9, -66.0)], $b);

    expect((new BuscadorCombinado([$primero, $segundo]))->buscar('algo', null, null))->toHaveCount(5);
    expect($b)->toBe(0);
});

it('el combinado sigue con los demás si uno no trae nada', function () {
    Http::fake([
        'nominatim.local/*' => Http::response('caido', 503),
        GEOREF => Http::response(respuestaGeoref([direccionGeoref('SARMIENTO', 'RIVADAVIA', 'Belén', -27.6496, -67.031)])),
    ]);
    config([
        'vehiculos.lugares.driver' => 'nominatim, georef',
        'vehiculos.lugares.nominatim_url' => 'http://nominatim.local',
        'vehiculos.lugares.georef_url' => 'https://apis.datos.gob.ar/georef/api',
        'vehiculos.lugares.provincia' => 'Catamarca',
    ]);

    $r = app(BuscadorLugares::class)->buscar('Sarmiento y Rivadavia', null, null);

    expect(app(BuscadorLugares::class))->toBeInstanceOf(BuscadorCombinado::class);
    expect(array_column($r, 'nombre'))->toBe(['Sarmiento y Rivadavia, Belén']);
    Http::assertSentCount(2);
});
