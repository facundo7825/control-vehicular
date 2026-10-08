<?php

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Events\ViajeActualizado;
use App\Filament\Resources\Viajes\Pages\CreateViaje;
use App\Filament\Resources\Viajes\Pages\ListViajes;
use App\Filament\Resources\Viajes\Pages\ViewViaje;
use App\Jobs\CompletarDirecciones;
use App\Listeners\AvisosViaje;
use App\Mapas\BuscadorFalso;
use App\Mapas\BuscadorGoogle;
use App\Mapas\BuscadorNominatim;
use App\Mapas\GeocodificadorInverso;
use App\Models\OfertaViaje;
use App\Models\PuntoRecorrido;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\CompletadorDirecciones;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\NotificadorFalso;

const NOMINATIM_INVERSO = 'nominatim.openstreetmap.org/reverse*';

const GEOCODING = 'maps.googleapis.com/maps/api/geocode/*';

/** Respuesta real del Nominatim propio (datos de Catamarca) para -28.4700, -65.7850. */
function respuestaInversa(array $cambios = []): array
{
    return array_replace_recursive([
        'lat' => '-28.4700653', 'lon' => '-65.7850009', 'name' => '',
        'display_name' => '129, San Martín, Centro, San Fernando del Valle de Catamarca, Departamento Capital, Catamarca, K4700, Argentina',
        'address' => [
            'house_number' => '129', 'road' => 'San Martín', 'suburb' => 'Centro',
            'city' => 'San Fernando del Valle de Catamarca', 'state' => 'Catamarca', 'country' => 'Argentina',
        ],
    ], $cambios);
}

/** Nominatim (el público por defecto) que no duerme de verdad: anota las esperas pedidas. */
function inversoSinEspera(array &$esperas, string $url = BuscadorNominatim::URL_PUBLICA): BuscadorNominatim
{
    return new BuscadorNominatim('Demo/1.0 (prueba)', function (int $micro) use (&$esperas) {
        $esperas[] = $micro;
    }, $url);
}

/** Geocodificador que siempre falla, y cuenta las consultas. */
function geocodificadorCaido(): GeocodificadorInverso
{
    return new class implements GeocodificadorInverso
    {
        public int $consultas = 0;

        /** @var list<?float> */
        public array $plazos = [];

        public function direccion(float $lat, float $lng, ?float $hasta = null): ?string
        {
            $this->consultas++;
            $this->plazos[] = $hasta;

            return null;
        }
    };
}

describe('geocodificación inversa', function () {
    it('se elige con LUGARES_DRIVER: georef y las listas usan Nominatim', function (string $driver, string $clase) {
        config(['vehiculos.lugares.driver' => $driver, 'vehiculos.mapas.google_api_key' => 'k']);

        expect(app(GeocodificadorInverso::class))->toBeInstanceOf($clase);
    })->with([
        ['nominatim', BuscadorNominatim::class],
        ['georef', BuscadorNominatim::class],
        ['nominatim,georef', BuscadorNominatim::class],
        ['google,georef', BuscadorNominatim::class],
        ['google', BuscadorGoogle::class],
        ['falso', BuscadorFalso::class],
    ]);

    it('el falso devuelve una dirección determinista, sin coordenadas', function () {
        $falso = new BuscadorFalso;

        expect($falso->direccion(-34.6037, -58.3816))->toBe($falso->direccion(-34.6037, -58.3816))
            ->toMatch('/^Calle Falsa \d+, Ciudad de prueba$/')
            ->and($falso->direccion(-34.6037, -58.3816))->not->toBe($falso->direccion(-34.609, -58.392));
    });

    it('Nominatim pide /reverse con los parámetros de la política y arma "calle altura, barrio"', function () {
        Http::fake([NOMINATIM_INVERSO => Http::response(respuestaInversa())]);
        $esperas = [];

        expect(inversoSinEspera($esperas)->direccion(-28.470012, -65.785049))->toBe('San Martín 129, Centro');

        Http::assertSent(function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return str_starts_with($req->url(), 'https://nominatim.openstreetmap.org/reverse?')
                && $req->header('User-Agent') === ['Demo/1.0 (prueba)']
                && $q === [
                    'format' => 'jsonv2', 'lat' => '-28.47', 'lon' => '-65.785', 'zoom' => '18',
                    'addressdetails' => '1', 'accept-language' => 'es',
                ];
        });
    });

    it('arma la dirección según lo que traiga la respuesta', function (array $fila, ?string $esperada) {
        expect(BuscadorNominatim::armarDireccion($fila))->toBe($esperada);
    })->with([
        'calle y altura' => [respuestaInversa(), 'San Martín 129, Centro'],
        'sin altura' => [respuestaInversa(['address' => ['house_number' => '']]), 'San Martín, Centro'],
        'en un pueblo' => [['address' => ['road' => 'Belgrano', 'house_number' => '40', 'town' => 'Andalgalá']], 'Belgrano 40, Andalgalá'],
        'sin barrio, la localidad' => [['address' => ['road' => 'Sarmiento', 'house_number' => '520', 'city' => 'San Fernando del Valle de Catamarca']], 'Sarmiento 520, San Fernando del Valle de Catamarca'],
        'el barrio antes que la localidad' => [['address' => ['road' => 'Sarmiento', 'house_number' => '520', 'suburb' => 'Centro', 'city' => 'San Fernando del Valle de Catamarca']], 'Sarmiento 520, Centro'],
        'solo la calle' => [['address' => ['road' => 'Ruta 38']], 'Ruta 38'],
        'sin calle, el nombre del lugar' => [['name' => 'Plaza 25 de Mayo', 'address' => ['city' => 'Catamarca']], 'Plaza 25 de Mayo'],
        'sin calle ni nombre, la localidad' => [['name' => '', 'address' => ['village' => 'Fiambalá']], 'Fiambalá'],
        'en un paraje' => [['address' => ['road' => 'Ruta 4', 'hamlet' => 'Las Juntas']], 'Ruta 4, Las Juntas'],
        'en un municipio' => [['address' => ['municipality' => 'Valle Viejo']], 'Valle Viejo'],
        'el barrio es "neighbourhood"' => [['address' => ['road' => 'Maipú', 'house_number' => '10', 'neighbourhood' => 'Villa Cubas', 'city' => 'Catamarca']], 'Maipú 10, Villa Cubas'],
        'sin nada' => [['error' => 'Unable to geocode'], null],
    ]);

    it('Nominatim cachea 24 h por punto redondeado a 4 decimales, también un punto sin dirección', function () {
        Http::fake([NOMINATIM_INVERSO => Http::sequence()
            ->push(respuestaInversa())
            ->push(['error' => 'Unable to geocode'])
            ->push(respuestaInversa())]);
        $esperas = [];
        $n = inversoSinEspera($esperas);

        $n->direccion(-28.47001, -65.78501);
        expect($n->direccion(-28.47002, -65.78502))->toBe('San Martín 129, Centro');
        Http::assertSentCount(1);

        expect($n->direccion(0.0, 0.0))->toBeNull()->and($n->direccion(0.0, 0.0))->toBeNull();
        Http::assertSentCount(2);

        $this->travel(25)->hours();
        $n->direccion(-28.47001, -65.78501);
        Http::assertSentCount(3);
    });

    it('Nominatim devuelve null ante una falla, no la cachea y corta 60 s (también la búsqueda)', function (string $falla) {
        $llamadas = 0;
        $caido = true;
        Http::fake(['nominatim.openstreetmap.org/*' => function () use ($falla, &$llamadas, &$caido) {
            $llamadas++;
            if (! $caido) {
                return Http::response(respuestaInversa());
            }

            return $falla === '500' ? Http::response('error', 500) : throw new ConnectionException('timeout');
        }]);
        $esperas = [];
        $n = inversoSinEspera($esperas);

        expect($n->direccion(-28.47, -65.785))->toBeNull()
            ->and($n->direccion(-28.48, -65.79))->toBeNull()
            ->and($n->buscar('plaza', null, null))->toBe([]);
        expect($llamadas)->toBe(1);

        $this->travel(61)->seconds();
        $caido = false;
        expect($n->direccion(-28.47, -65.785))->toBe('San Martín 129, Centro');
    })->with(['500', 'sin conexion']);

    it('Nominatim espera como mucho 2 s por punto', function () {
        $timeout = null;
        Http::fake(['127.0.0.1:8088/*' => function (Request $req, array $opciones) use (&$timeout) {
            $timeout = $opciones['timeout'] ?? null;

            return Http::response(respuestaInversa());
        }]);
        $esperas = [];

        inversoSinEspera($esperas, 'http://127.0.0.1:8088')->direccion(-28.47, -65.785);

        expect($timeout)->toBeGreaterThan(1.5)->toBeLessThanOrEqual(2.0);
    });

    it('Nominatim público respeta 1 pedido por segundo; uno propio no espera', function () {
        Http::fake(['*' => Http::response(respuestaInversa())]);
        $esperas = [];
        $publico = inversoSinEspera($esperas);

        $publico->direccion(-28.47, -65.785);
        $publico->direccion(-28.48, -65.79);
        expect($esperas)->toHaveCount(1)->and($esperas[0])->toBeGreaterThan(0)->toBeLessThanOrEqual(1_000_000);

        $propias = [];
        $propio = inversoSinEspera($propias, 'http://127.0.0.1:8088');
        $propio->direccion(-28.47, -65.785);
        $propio->direccion(-28.48, -65.79);
        expect($propias)->toBe([]);
        Http::assertSent(fn (Request $req) => str_starts_with($req->url(), 'http://127.0.0.1:8088/reverse?'));
    });

    it('Nominatim público con el lock tomado devuelve null en menos de 2 s y sin cortar', function () {
        Http::fake([NOMINATIM_INVERSO => Http::response(respuestaInversa())]);
        $lock = Cache::lock('lugares:nominatim:lock', 15);
        expect($lock->get())->toBeTrue();
        $esperas = [];
        $n = inversoSinEspera($esperas);

        $inicio = microtime(true);
        expect($n->direccion(-28.47, -65.785))->toBeNull()
            ->and(microtime(true) - $inicio)->toBeLessThan(2.0);
        Http::assertSentCount(0);

        $lock->release();
        expect($n->direccion(-28.47, -65.785))->not->toBeNull();
    });

    it('Nominatim no corta por un timeout que achicó el propio plazo (sí con el timeout completo)', function () {
        $intentos = 0;
        Http::fake(['127.0.0.1:8088/*' => function () use (&$intentos) {
            $intentos++;

            throw new ConnectionException('timeout');
        }]);
        $esperas = [];
        $n = inversoSinEspera($esperas, 'http://127.0.0.1:8088');

        // Quedaba 1,5 s del plazo compartido: el timeout fue de 1,5 s, no de 2.
        expect($n->direccion(-28.47, -65.785, microtime(true) + 1.5))->toBeNull()
            ->and(BuscadorNominatim::cortado())->toBeFalse();

        expect($n->direccion(-28.48, -65.79))->toBeNull()
            ->and(BuscadorNominatim::cortado())->toBeTrue()
            ->and($intentos)->toBe(2);
    });

    it('Nominatim no pide (ni toma el lock ni marca el último pedido) si del plazo queda menos de 1 s', function () {
        Http::fake();
        $esperas = [];
        $n = inversoSinEspera($esperas);

        expect($n->direccion(-28.47, -65.785, microtime(true) + 0.5))->toBeNull();

        Http::assertNothingSent();
        expect(Cache::has('lugares:nominatim:ultimo'))->toBeFalse()
            ->and(Cache::lock('lugares:nominatim:lock', 15)->get())->toBeTrue();
    });

    it('Nominatim público no espera ni pide si la espera de 1 s dejaría menos de 1 s de plazo', function () {
        Http::fake();
        $ultimo = microtime(true);
        Cache::put('lugares:nominatim:ultimo', $ultimo, 60);
        $esperas = [];

        expect(inversoSinEspera($esperas)->direccion(-28.47, -65.785, microtime(true) + 1.5))->toBeNull();

        expect($esperas)->toBe([])
            ->and(Cache::get('lugares:nominatim:ultimo'))->toBe($ultimo)
            ->and(BuscadorNominatim::cortado())->toBeFalse();
        Http::assertNothingSent();
    });

    it('Nominatim no deja en el log el punto ni la URL', function () {
        Http::fake([NOMINATIM_INVERSO => fn (Request $req) => throw new ConnectionException('cURL error 28 for '.$req->url())]);
        Log::spy();
        $esperas = [];

        inversoSinEspera($esperas)->direccion(-28.47123, -65.78567);

        Log::shouldHaveReceived('warning')->withArgs(function (string $msg, array $ctx = []) {
            $todo = $msg.json_encode($ctx);

            return ! str_contains($todo, '28.47') && ! str_contains($todo, '65.78') && ! str_contains($todo, 'nominatim.openstreetmap');
        })->once();
    });

    it('Google usa la Geocoding API en castellano con el punto redondeado', function () {
        Http::fake([GEOCODING => Http::response(['status' => 'OK', 'results' => [
            ['formatted_address' => 'San Martín 129, K4700 San Fernando del Valle de Catamarca, Catamarca, Argentina'],
        ]])]);

        expect((new BuscadorGoogle('clave'))->direccion(-28.470012, -65.785049))
            ->toBe('San Martín 129, K4700 San Fernando del Valle de Catamarca, Catamarca, Argentina');

        Http::assertSent(function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return $q === ['latlng' => '-28.47,-65.785', 'language' => 'es', 'key' => 'clave'];
        });
    });

    it('Google devuelve null sin resultados o ante error, sin dejar el punto en el log', function () {
        Log::spy();
        Http::fake([GEOCODING => Http::sequence()
            ->push(['status' => 'ZERO_RESULTS', 'results' => []])
            ->push(['status' => 'REQUEST_DENIED', 'error_message' => 'clave inválida'])
            ->push('error', 500)]);
        $google = new BuscadorGoogle('clave');

        expect($google->direccion(-28.47123, -65.78567))->toBeNull()
            ->and($google->direccion(-28.47123, -65.78567))->toBeNull()
            ->and($google->direccion(-28.47123, -65.78567))->toBeNull();

        Log::shouldHaveReceived('warning')->withArgs(function (string $msg, array $ctx = []) {
            $todo = $msg.json_encode($ctx);

            return ! str_contains($todo, '28.47') && ! str_contains($todo, 'clave');
        })->twice();
    });
});

describe('GET /api/lugares/inverso', function () {
    it('responde la dirección del punto', function () {
        $this->actingAs(Usuario::factory()->create())
            ->getJson('/api/lugares/inverso?lat=-34.6037&lng=-58.3816')
            ->assertOk()
            ->assertExactJson(['direccion' => (new BuscadorFalso)->direccion(-34.6037, -58.3816)]);
    });

    it('responde null si no se pudo obtener', function () {
        $this->app->instance(GeocodificadorInverso::class, geocodificadorCaido());

        $this->actingAs(Usuario::factory()->create())
            ->getJson('/api/lugares/inverso?lat=-34.6037&lng=-58.3816')
            ->assertOk()
            ->assertExactJson(['direccion' => null]);
    });

    it('valida lat y lng', function (string $consulta, string $campo) {
        $this->actingAs(Usuario::factory()->create())
            ->getJson("/api/lugares/inverso?$consulta")
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);
    })->with([
        ['lng=-58.38', 'lat'],
        ['lat=-34.6', 'lng'],
        ['lat=abc&lng=-58.38', 'lat'],
        ['lat=-91&lng=-58.38', 'lat'],
        ['lat=-34.6&lng=181', 'lng'],
    ]);

    it('exige sesión de un usuario activo', function () {
        $this->getJson('/api/lugares/inverso?lat=-34.6&lng=-58.38')->assertUnauthorized();

        $this->actingAs(Usuario::factory()->create(['activo' => false]))
            ->getJson('/api/lugares/inverso?lat=-34.6&lng=-58.38')
            ->assertForbidden();
    });

    it('comparte el límite por minuto de /api/lugares', function () {
        config(['vehiculos.lugares.limite_por_minuto' => 2]);
        $u = Usuario::factory()->create();

        $this->actingAs($u)->getJson('/api/lugares?q=obelisco')->assertOk();
        $this->actingAs($u)->getJson('/api/lugares/inverso?lat=-34.6&lng=-58.38')->assertOk();
        $this->actingAs($u)->getJson('/api/lugares/inverso?lat=-34.6&lng=-58.38')->assertTooManyRequests();
    });
});

describe('al crear un viaje', function () {
    beforeEach(function () {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    });

    $pedido = fn (array $extra = []) => [
        'modo' => 'mas_cercano',
        'origen_lat' => -34.600, 'origen_lng' => -58.380,
        'destino_lat' => -34.609, 'destino_lng' => -58.392,
        ...$extra,
    ];

    it('completa las direcciones que faltan antes de guardar', function () use ($pedido) {
        choferEnTurno(-34.601, -58.381);
        $falso = new BuscadorFalso;

        $this->actingAs(Usuario::factory()->create())
            ->postJson('/api/viajes', $pedido(['destino_direccion' => 'Tribunales']))
            ->assertCreated()
            ->assertJsonPath('origen.direccion', $falso->direccion(-34.600, -58.380))
            ->assertJsonPath('destino.direccion', 'Tribunales');

        expect(Viaje::sole()->origen_direccion)->toBe($falso->direccion(-34.600, -58.380));
        Queue::assertNotPushed(CompletarDirecciones::class);
    });

    it('no consulta si ya llegan las dos direcciones', function () use ($pedido) {
        $this->app->instance(GeocodificadorInverso::class, $caido = geocodificadorCaido());

        $this->actingAs(Usuario::factory()->create())
            ->postJson('/api/viajes', $pedido(['origen_direccion' => 'Talcahuano 550', 'destino_direccion' => 'Tribunales']))
            ->assertCreated();

        expect($caido->consultas)->toBe(0);
    });

    it('consulta fuera de la transacción y, si falla, guarda sin dirección y encola el reintento', function () use ($pedido) {
        config(['vehiculos.lugares.driver' => 'nominatim', 'vehiculos.lugares.nominatim_url' => 'http://127.0.0.1:8088']);
        $niveles = [];
        $base = DB::transactionLevel(); // la de RefreshDatabase
        Http::fake(['127.0.0.1:8088/*' => function () use (&$niveles) {
            $niveles[] = DB::transactionLevel();

            return Http::response('error', 500);
        }]);

        $this->actingAs(Usuario::factory()->create())
            ->postJson('/api/viajes', $pedido())
            ->assertCreated()
            ->assertJsonPath('origen.direccion', null)
            ->assertJsonPath('destino.direccion', null);

        // La primera falla corta 60 s: el destino ya no consulta.
        expect($niveles)->toBe([$base]);
        $viaje = Viaje::sole();
        Queue::assertPushed(CompletarDirecciones::class, fn (CompletarDirecciones $job) => $job->viajeId === $viaje->id
            && $job->delay->eq(now()->addSeconds(CompletarDirecciones::ESPERA_SEG)));
    });

    it('los dos puntos comparten un solo plazo de 2 s', function () use ($pedido) {
        $this->app->instance(GeocodificadorInverso::class, $caido = geocodificadorCaido());

        $antes = microtime(true);
        $this->actingAs(Usuario::factory()->create())->postJson('/api/viajes', $pedido())->assertCreated();

        expect($caido->plazos)->toHaveCount(2)
            ->and($caido->plazos[0])->toBe($caido->plazos[1])
            ->and($caido->plazos[0])->toBeGreaterThanOrEqual($antes + GeocodificadorInverso::PLAZO_SEG)
            ->toBeLessThanOrEqual(microtime(true) + GeocodificadorInverso::PLAZO_SEG);
    });

    it('completa también las reservas', function () use ($pedido) {
        fijarDuracionRuta(1500);
        $chofer = Usuario::factory()->chofer()->create();

        $this->actingAs(Usuario::factory()->create())
            ->postJson('/api/reservas', $pedido([
                'programado_para' => '2026-10-02T12:00:00-03:00', 'modo' => 'especifico', 'chofer_id' => $chofer->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('origen.direccion', (new BuscadorFalso)->direccion(-34.600, -58.380))
            ->assertJsonPath('destino.direccion', (new BuscadorFalso)->direccion(-34.609, -58.392));
    });

    it('completa también los que crea el admin desde el panel', function () {
        $this->actingAs(Usuario::factory()->admin()->create());
        $solicitante = Usuario::factory()->create();

        Livewire::test(CreateViaje::class)
            ->fillForm([
                'solicitante_id' => $solicitante->id, 'tipo' => 'inmediato', 'modo' => 'mas_cercano',
                'origen_lat' => -34.6037, 'origen_lng' => -58.3816,
                'destino_direccion' => 'Casa de Gobierno', 'destino_lat' => -34.608, 'destino_lng' => -58.37,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Viaje::sole())
            ->origen_direccion->toBe((new BuscadorFalso)->direccion(-34.6037, -58.3816))
            ->destino_direccion->toBe('Casa de Gobierno');
    });
});

describe('job CompletarDirecciones', function () {
    it('completa los puntos sin dirección, sin pisar los que la tienen, y avisa solo con datos', function () {
        Event::fake([ViajeActualizado::class]);
        $viaje = Viaje::factory()->create(['origen_direccion' => null, 'destino_direccion' => 'Tribunales']);

        $job = (new CompletarDirecciones($viaje->id))->withFakeQueueInteractions();
        $job->handle(app(CompletadorDirecciones::class));

        expect($viaje->fresh())
            ->origen_direccion->toBe((new BuscadorFalso)->direccion(-34.6037, -58.3816))
            ->destino_direccion->toBe('Tribunales');
        Event::assertDispatched(ViajeActualizado::class, fn (ViajeActualizado $e) => $e->viaje->is($viaje) && $e->soloDatos);
        $job->assertNotReleased();
    });

    it('también avisa a los choferes con una oferta pendiente del viaje', function () {
        Event::fake([ViajeActualizado::class]);
        $ofrecido = Usuario::factory()->chofer()->create();
        $rechazo = Usuario::factory()->chofer()->create();
        $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Ofrecido]);
        foreach ([[$ofrecido, ResultadoOferta::Pendiente], [$rechazo, ResultadoOferta::Rechazada]] as [$chofer, $resultado]) {
            OfertaViaje::create([
                'viaje_id' => $viaje->id, 'chofer_id' => $chofer->id, 'resultado' => $resultado,
                'ofrecido_en' => now(), 'vence_en' => now()->addSeconds(30),
            ]);
        }

        (new CompletarDirecciones($viaje->id))->withFakeQueueInteractions()->handle(app(CompletadorDirecciones::class));

        Event::assertDispatched(ViajeActualizado::class, function (ViajeActualizado $e) use ($ofrecido) {
            $canales = array_map(fn ($c) => $c->name, $e->broadcastOn());

            return $e->choferesConOferta === [$ofrecido->id] && $e->soloDatos
                && in_array("private-chofer.{$ofrecido->id}", $canales, true);
        });
    });

    it('si sigue fallando se reintenta, cada vez más espaciado, hasta 3 intentos', function () {
        Event::fake([ViajeActualizado::class]);
        $this->app->instance(GeocodificadorInverso::class, geocodificadorCaido());
        $viaje = Viaje::factory()->create();

        $job = (new CompletarDirecciones($viaje->id))->withFakeQueueInteractions();
        $job->handle(app(CompletadorDirecciones::class));
        $job->assertReleased(60);

        $ultimo = (new CompletarDirecciones($viaje->id))->withFakeQueueInteractions();
        $ultimo->job->attempts = 3;
        $ultimo->handle(app(CompletadorDirecciones::class));
        $ultimo->assertNotReleased();

        expect($job->tries)->toBe(3);
        Event::assertNotDispatched(ViajeActualizado::class);
    });

    it('no hace nada si el viaje ya tiene las dos direcciones o no existe', function () {
        $this->app->instance(GeocodificadorInverso::class, $caido = geocodificadorCaido());
        $viaje = Viaje::factory()->create(['origen_direccion' => 'A', 'destino_direccion' => 'B']);

        (new CompletarDirecciones($viaje->id))->handle(app(CompletadorDirecciones::class));
        (new CompletarDirecciones(999))->handle(app(CompletadorDirecciones::class));

        expect($caido->consultas)->toBe(0);
    });

    it('el aviso de solo datos no manda push', function () {
        $push = new NotificadorFalso;
        $this->app->instance(Notificador::class, $push);
        $chofer = choferEnTurno();
        $viaje = Viaje::factory()->create([
            'chofer_id' => $chofer->id, 'vehiculo_id' => $chofer->turnoAbierto->vehiculo_id, 'estado' => EstadoViaje::Aceptado,
        ]);

        app(AvisosViaje::class)->handleViajeActualizado(new ViajeActualizado($viaje, soloDatos: true));
        expect($push->enviados)->toBe([]);

        app(AvisosViaje::class)->handleViajeActualizado(new ViajeActualizado($viaje));
        expect($push->enviados)->not->toBe([]);
    });
});

describe('comando vehiculos:completar-direcciones', function () {
    it('completa las direcciones faltantes de los viajes guardados', function () {
        $sinOrigen = Viaje::factory()->create(['origen_direccion' => null, 'destino_direccion' => 'Tribunales']);
        $sinNada = Viaje::factory()->create();
        $completo = Viaje::factory()->create(['origen_direccion' => 'A', 'destino_direccion' => 'B']);

        $this->artisan('vehiculos:completar-direcciones')
            ->expectsOutput('Viajes completos: 2. Sin dirección todavía: 0.')
            ->assertSuccessful();

        expect($sinOrigen->fresh()->destino_direccion)->toBe('Tribunales')
            ->and($sinOrigen->fresh()->origen_direccion)->not->toBeNull()
            ->and($sinNada->fresh()->destino_direccion)->not->toBeNull()
            ->and($completo->fresh()->only(['origen_direccion', 'destino_direccion']))->toBe(['origen_direccion' => 'A', 'destino_direccion' => 'B']);
    });

    it('revisa como mucho --limite viajes, los más nuevos primero', function () {
        $viejo = Viaje::factory()->create();
        $nuevo = Viaje::factory()->create();

        $this->artisan('vehiculos:completar-direcciones --limite=1')->assertSuccessful();

        expect($nuevo->fresh()->origen_direccion)->not->toBeNull()
            ->and($viejo->fresh()->origen_direccion)->toBeNull();
    });

    it('rechaza un límite inválido', function () {
        $this->artisan('vehiculos:completar-direcciones --limite=0')->assertFailed();
    });

    it('sigue con los demás si un punto no tiene dirección', function () {
        $this->app->instance(GeocodificadorInverso::class, $sinDireccion = geocodificadorCaido());
        Viaje::factory()->count(8)->create();

        $this->artisan('vehiculos:completar-direcciones')
            ->doesntExpectOutputToContain('pausa')
            ->expectsOutput('Viajes completos: 0. Sin dirección todavía: 8.')
            ->assertSuccessful();

        expect($sinDireccion->consultas)->toBe(16);
    });

    it('se detiene si el geocodificador entra en su corte por una falla', function () {
        config(['vehiculos.lugares.driver' => 'nominatim', 'vehiculos.lugares.nominatim_url' => 'http://127.0.0.1:8088']);
        Http::fake(['127.0.0.1:8088/*' => Http::response('error', 500)]);
        Viaje::factory()->count(3)->create();

        $this->artisan('vehiculos:completar-direcciones')
            ->expectsOutputToContain('quedó en pausa por 60 s')
            ->expectsOutput('Viajes completos: 0. Sin dirección todavía: 1.')
            ->assertSuccessful();

        Http::assertSentCount(1);
    });
});

describe('panel sin coordenadas', function () {
    beforeEach(function () {
        Queue::fake();
        $this->actingAs(Usuario::factory()->admin()->create());
    });

    it('la lista y el detalle muestran "Ubicación marcada en el mapa" en lugar de las coordenadas', function () {
        $viaje = Viaje::factory()->create(['origen_direccion' => 'Talcahuano 550', 'destino_direccion' => null]);
        PuntoRecorrido::create(['viaje_id' => $viaje->id, 'lat' => -34.6011, 'lng' => -58.3822, 'registrado_en' => now()]);

        Livewire::test(ListViajes::class)
            ->assertTableColumnStateSet('destino_direccion', 'Ubicación marcada en el mapa', $viaje)
            ->assertDontSee('-34.609');

        Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
            ->assertSee('Talcahuano 550')
            ->assertSee('Ubicación marcada en el mapa')
            ->assertDontSee('-34.609')
            ->assertDontSee('-58.392')
            ->assertDontSee('-34.6011');
    });
});
