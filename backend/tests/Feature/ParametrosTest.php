<?php

use App\Models\Parametro;
use App\Models\Usuario;
use App\Servicios\Parametros;

it('usa el valor por defecto de config', function () {
    expect(app(Parametros::class)->entero('oferta_segundos'))->toBe(30);
});

it('prioriza el valor guardado en la tabla', function () {
    Parametro::create(['clave' => 'oferta_segundos', 'valor' => '45']);

    expect(app(Parametros::class)->entero('oferta_segundos'))->toBe(45);
});

it('falla ante un parámetro desconocido', function () {
    app(Parametros::class)->entero('no_existe');
})->throws(InvalidArgumentException::class);

it('expone la configuración que necesita la app', function () {
    $this->actingAs(Usuario::factory()->create())
        ->getJson('/api/configuracion')
        ->assertOk()
        ->assertExactJson([
            'gps_turno_seg' => 10, 'gps_viaje_seg' => 5, 'oferta_segundos' => 30, 'lugares_autocompletar' => true,
            // Por defecto, el OSM público (solo desarrollo y demos).
            'teselas' => [
                'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                'atribucion' => '© OpenStreetMap contributors',
                'atribucion_url' => 'https://www.openstreetmap.org/copyright',
                'tms' => false,
                'max_zoom' => 19,
            ],
        ]);
});

it('expone el mapa de fondo configurado', function () {
    config(['vehiculos.mapas.teselas' => [
        'url' => 'https://mapas.ejemplo.gob.ar/tms/{z}/{x}/{y}.png',
        'atribucion' => 'IGN · OpenStreetMap',
        'atribucion_url' => '',
        'tms' => true,
        'max_zoom' => '15',
    ]]);

    $this->actingAs(Usuario::factory()->create())
        ->getJson('/api/configuracion')
        ->assertOk()
        ->assertJsonPath('teselas', [
            'url' => 'https://mapas.ejemplo.gob.ar/tms/{z}/{x}/{y}.png',
            'atribucion' => 'IGN · OpenStreetMap',
            'atribucion_url' => null, // vacía: los créditos no llevan enlace
            'tms' => true,
            'max_zoom' => 15,
        ]);
});

it('lee el mapa de fondo de las variables de entorno', function () {
    $variables = [
        'MAPAS_TESELAS_URL' => 'http://mapas.local:8080/styles/basico/{z}/{x}/{y}.png',
        'MAPAS_TESELAS_ATRIBUCION' => '© OpenStreetMap contributors · Mapa propio',
        'MAPAS_TESELAS_ATRIBUCION_URL' => 'https://mapas.local/creditos',
        'MAPAS_TESELAS_TMS' => 'true',
        'MAPAS_TESELAS_MAX_ZOOM' => '17',
    ];
    foreach ($variables as $nombre => $valor) {
        $_SERVER[$nombre] = $_ENV[$nombre] = $valor;
    }

    try {
        $teselas = (require config_path('vehiculos.php'))['mapas']['teselas'];
    } finally {
        foreach (array_keys($variables) as $nombre) {
            unset($_SERVER[$nombre], $_ENV[$nombre]);
        }
    }

    expect($teselas)->toBe([
        'url' => 'http://mapas.local:8080/styles/basico/{z}/{x}/{y}.png',
        'atribucion' => '© OpenStreetMap contributors · Mapa propio',
        'atribucion_url' => 'https://mapas.local/creditos',
        'tms' => true,
        'max_zoom' => 17,
    ]);
});

it('solo permite autocompletar lugares si el buscador lo admite (Nominatim público no)', function (string $driver, bool $autocompletar, array $extra = []) {
    config(['vehiculos.lugares.driver' => $driver, ...$extra]);

    $this->actingAs(Usuario::factory()->create())
        ->getJson('/api/configuracion')
        ->assertOk()
        ->assertJsonPath('lugares_autocompletar', $autocompletar);
})->with([
    ['nominatim', false],
    ['google', true],
    ['falso', true],
    ['georef', true],
    ['desconocido', false],
    'Nominatim propio' => ['nominatim', true, ['vehiculos.lugares.nominatim_url' => 'http://nominatim.local:8080']],
    'combinado con Nominatim público' => ['nominatim,georef', false],
    'combinado con Nominatim propio' => ['nominatim, georef', true, ['vehiculos.lugares.nominatim_url' => 'http://nominatim.local:8080']],
    'combinado con uno desconocido' => ['georef,desconocido', false],
    'forzado a sí' => ['nominatim', true, ['vehiculos.lugares.autocompletar' => 'true']],
    'forzado a no' => ['google', false, ['vehiculos.lugares.autocompletar' => false]],
]);

it('trae los parámetros de reservas con sus valores por defecto', function (string $clave, int $valor) {
    expect(app(Parametros::class)->entero($clave))->toBe($valor);
})->with([
    ['margen_duracion_reserva_min', 15],
    ['duracion_reserva_por_defecto_min', 60],
    ['recordatorio_reserva_1_min', 1440],
    ['recordatorio_reserva_2_min', 30],
    ['alerta_sin_turno_min', 15],
]);
