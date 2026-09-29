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
        ->assertExactJson(['gps_turno_seg' => 10, 'gps_viaje_seg' => 5, 'oferta_segundos' => 30]);
});
