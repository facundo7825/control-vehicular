<?php

use App\Mapas\BuscadorFalso;
use App\Mapas\BuscadorGoogle;
use App\Mapas\BuscadorLugares;
use App\Mapas\BuscadorNominatim;
use App\Models\Usuario;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const NOMINATIM = 'nominatim.openstreetmap.org/*';

function respuestaNominatim(): array
{
    return [
        ['name' => 'Obelisco', 'display_name' => 'Obelisco, Avenida 9 de Julio, Buenos Aires, Argentina', 'lat' => '-34.6037', 'lon' => '-58.3816'],
        ['name' => '', 'display_name' => 'Corrientes 1234, San Nicolás, Buenos Aires, Argentina', 'lat' => '-34.6040', 'lon' => '-58.3900'],
    ];
}

/** Buscador de Nominatim que no duerme de verdad: anota las esperas pedidas (en microsegundos). */
function nominatimSinEspera(array &$esperas): BuscadorNominatim
{
    return new BuscadorNominatim('Demo/1.0 (prueba)', function (int $micro) use (&$esperas) {
        $esperas[] = $micro;
    });
}

it('devuelve los lugares con la forma pedida (driver falso)', function () {
    $r = $this->actingAs(Usuario::factory()->create())->getJson('/api/lugares?q=obelisco')->assertOk();

    expect($r->json())->toBeArray()->not->toBeEmpty()->and(count($r->json()))->toBeLessThanOrEqual(5);
    expect(array_keys($r->json('0')))->toEqualCanonicalizing(['nombre', 'direccion', 'lat', 'lng']);
    expect($r->json('0.lat'))->toBeFloat()->and($r->json('0.lng'))->toBeFloat();
});

it('acepta lat y lng opcionales y los valida', function () {
    $u = Usuario::factory()->create();

    $this->actingAs($u)->getJson('/api/lugares?q=obelisco&lat=-34.6&lng=-58.4')->assertOk();
    $this->actingAs($u)->getJson('/api/lugares?q=obelisco&lat=abc&lng=-58.4')->assertUnprocessable()->assertJsonValidationErrors('lat');
    $this->actingAs($u)->getJson('/api/lugares?q=obelisco&lat=-34.6')->assertUnprocessable()->assertJsonValidationErrors('lng');
});

it('rechaza textos de menos de 3 caracteres', function () {
    $u = Usuario::factory()->create();

    $this->actingAs($u)->getJson('/api/lugares?q=ab')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->actingAs($u)->getJson('/api/lugares')->assertUnprocessable()->assertJsonValidationErrors('q');
});

it('exige sesión', function () {
    $this->getJson('/api/lugares?q=obelisco')->assertUnauthorized();
});

it('elige el driver por configuración', function () {
    config(['vehiculos.lugares.driver' => 'nominatim']);
    expect(app(BuscadorLugares::class))->toBeInstanceOf(BuscadorNominatim::class);

    config(['vehiculos.lugares.driver' => 'google', 'vehiculos.mapas.google_api_key' => 'k']);
    expect(app(BuscadorLugares::class))->toBeInstanceOf(BuscadorGoogle::class);

    config(['vehiculos.lugares.driver' => 'falso']);
    expect(app(BuscadorLugares::class))->toBeInstanceOf(BuscadorFalso::class);
});

it('Nominatim pide con los parámetros y el User-Agent de la política y mapea el resultado', function () {
    Http::fake([NOMINATIM => Http::response(respuestaNominatim())]);
    $esperas = [];

    $r = nominatimSinEspera($esperas)->buscar('Obelisco', -34.6, -58.4);

    expect($r)->toBe([
        ['nombre' => 'Obelisco', 'direccion' => 'Obelisco, Avenida 9 de Julio, Buenos Aires, Argentina', 'lat' => -34.6037, 'lng' => -58.3816],
        ['nombre' => 'Corrientes 1234', 'direccion' => 'Corrientes 1234, San Nicolás, Buenos Aires, Argentina', 'lat' => -34.604, 'lng' => -58.39],
    ]);
    Http::assertSent(function (Request $req) {
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return str_starts_with($req->url(), 'https://nominatim.openstreetmap.org/search?')
            && $req->header('User-Agent') === ['Demo/1.0 (prueba)']
            && $q['q'] === 'Obelisco' && $q['format'] === 'jsonv2' && $q['addressdetails'] === '1'
            && $q['limit'] === '5' && $q['countrycodes'] === 'ar' && $q['accept-language'] === 'es'
            && isset($q['viewbox']) && count(explode(',', $q['viewbox'])) === 4;
    });
});

it('Nominatim no manda viewbox sin coordenadas', function () {
    Http::fake([NOMINATIM => Http::response([])]);
    $esperas = [];

    nominatimSinEspera($esperas)->buscar('Obelisco', null, null);

    Http::assertSent(fn (Request $req) => ! str_contains($req->url(), 'viewbox'));
});

it('Nominatim cachea 24 h por consulta normalizada', function () {
    Http::fake([NOMINATIM => Http::response(respuestaNominatim())]);
    $esperas = [];
    $b = nominatimSinEspera($esperas);

    $primera = $b->buscar('  Obelisco ', null, null);
    $segunda = $b->buscar('obelisco', null, null);

    expect($segunda)->toBe($primera);
    Http::assertSentCount(1);

    $this->travel(25)->hours();
    $b->buscar('obelisco', null, null);
    Http::assertSentCount(2);
});

it('Nominatim devuelve [] ante error y no cachea el fallo', function () {
    Http::fake([NOMINATIM => Http::sequence()->push('error', 500)->push(respuestaNominatim())]);
    $esperas = [];
    $b = nominatimSinEspera($esperas);

    expect($b->buscar('obelisco', null, null))->toBe([]);
    expect($b->buscar('obelisco', null, null))->toHaveCount(2);
});

it('Nominatim devuelve [] si no hay conexión', function () {
    Http::fake([NOMINATIM => fn () => throw new ConnectionException('timeout')]);
    $esperas = [];

    expect(nominatimSinEspera($esperas)->buscar('obelisco', null, null))->toBe([]);
});

it('Nominatim espera para respetar 1 pedido por segundo entre consultas distintas', function () {
    Http::fake([NOMINATIM => Http::response(respuestaNominatim())]);
    $esperas = [];
    $b = nominatimSinEspera($esperas);

    $b->buscar('obelisco', null, null);
    expect($esperas)->toBe([]);

    $b->buscar('plaza de mayo', null, null);
    expect($esperas)->toHaveCount(1)->and($esperas[0])->toBeGreaterThan(0)->toBeLessThanOrEqual(1_000_000);

    // Una consulta repetida sale del cache: no espera ni llama.
    $b->buscar('plaza de mayo', null, null);
    expect($esperas)->toHaveCount(1);
    Http::assertSentCount(2);
});

it('Google usa Places Text Search (New) y mapea el resultado', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(['places' => [
        ['displayName' => ['text' => 'Obelisco'], 'formattedAddress' => 'Av. 9 de Julio, C1043 CABA', 'location' => ['latitude' => -34.6037, 'longitude' => -58.3816]],
    ]])]);

    $r = (new BuscadorGoogle('clave'))->buscar('obelisco', -34.6, -58.4);

    expect($r)->toBe([['nombre' => 'Obelisco', 'direccion' => 'Av. 9 de Julio, C1043 CABA', 'lat' => -34.6037, 'lng' => -58.3816]]);
    Http::assertSent(function (Request $req) {
        return $req->url() === 'https://places.googleapis.com/v1/places:searchText'
            && $req->method() === 'POST'
            && $req->header('X-Goog-Api-Key') === ['clave']
            && str_contains($req->header('X-Goog-FieldMask')[0], 'places.location')
            && $req['textQuery'] === 'obelisco' && $req['languageCode'] === 'es' && $req['regionCode'] === 'AR'
            && $req['maxResultCount'] === 5
            && $req['locationBias']['circle']['center'] === ['latitude' => -34.6, 'longitude' => -58.4];
    });
});

it('Google devuelve [] ante error o falta de resultados', function () {
    Http::fake(['places.googleapis.com/*' => Http::sequence()->push(['error' => ['status' => 'PERMISSION_DENIED']], 403)->push([])]);
    $b = new BuscadorGoogle('clave');

    expect($b->buscar('obelisco', null, null))->toBe([]);
    expect($b->buscar('obelisco', null, null))->toBe([]);
});
