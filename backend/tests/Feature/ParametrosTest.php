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
        ]);
});

it('solo permite autocompletar lugares si el buscador lo admite (Nominatim no)', function (string $driver, bool $autocompletar) {
    config(['vehiculos.lugares.driver' => $driver]);

    $this->actingAs(Usuario::factory()->create())
        ->getJson('/api/configuracion')
        ->assertOk()
        ->assertJsonPath('lugares_autocompletar', $autocompletar);
})->with([
    ['nominatim', false],
    ['google', true],
    ['falso', true],
    ['desconocido', false],
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
