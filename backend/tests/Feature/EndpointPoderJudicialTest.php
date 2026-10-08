<?php

use App\Identidad\EndpointPoderJudicial;
use App\Identidad\IdentidadNoDisponible;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'vehiculos.identidad.url' => 'https://pj.test/api/sesion',
        'vehiculos.identidad.campos' => [
            'id_externo' => 'data.legajo', 'nombre' => 'data.nombre_completo',
            'cargo' => 'data.cargo', 'telefono' => 'data.telefono',
        ],
    ]);
});

it('mapea la respuesta del endpoint del PJ según los campos configurados', function () {
    Http::fake(['pj.test/*' => Http::response(['data' => [
        'legajo' => 4455, 'nombre_completo' => 'María Gómez', 'cargo' => 'Juez', 'telefono' => '381555',
    ]])]);

    $datos = (new EndpointPoderJudicial)->validar('tok');

    expect($datos->idExterno)->toBe('4455')
        ->and($datos->nombre)->toBe('María Gómez')
        ->and($datos->cargo)->toBe('Juez');
    Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer tok'));
});

it('devuelve null si el PJ responde 401', function () {
    Http::fake(['pj.test/*' => Http::response([], 401)]);

    expect((new EndpointPoderJudicial)->validar('tok'))->toBeNull();
});

it('lanza IdentidadNoDisponible si el PJ responde 500', function () {
    Http::fake(['pj.test/*' => Http::response([], 500)]);

    (new EndpointPoderJudicial)->validar('tok');
})->throws(IdentidadNoDisponible::class);

it('lee la dependencia si se configuró su campo', function () {
    config(['vehiculos.identidad.campos.dependencia' => 'data.oficina.nombre']);
    Http::fake(['pj.test/*' => Http::response(['data' => [
        'legajo' => 1, 'nombre_completo' => 'Ana', 'oficina' => ['nombre' => '  Fuero Penal '],
    ]])]);

    expect((new EndpointPoderJudicial)->validar('tok')->dependencia)->toBe('Fuero Penal');
});

it('sin el campo de dependencia configurado, no la informa', function () {
    config(['vehiculos.identidad.campos.dependencia' => '']);
    Http::fake(['pj.test/*' => Http::response(['data' => [
        'legajo' => 1, 'nombre_completo' => 'Ana', 'dependencia' => 'Fuero Penal',
    ]])]);

    expect((new EndpointPoderJudicial)->validar('tok')->dependencia)->toBeNull();
});
