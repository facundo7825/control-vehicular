<?php

use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Models\Usuario;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'rol:chofer'])->get('/api/_solo-chofer', fn () => 'ok');
    Route::middleware('api')->get('/api/_regla', fn () => throw new ReglaNegocio('No se puede.'));
    Route::middleware('api')->get('/api/_prohibido', fn () => throw new AccionNoPermitida('Prohibido.'));
});

it('deja pasar a un chofer', function () {
    $this->actingAs(Usuario::factory()->chofer()->create())->getJson('/api/_solo-chofer')->assertOk();
});

it('rechaza a un solicitante con 403', function () {
    $this->actingAs(Usuario::factory()->create())->getJson('/api/_solo-chofer')->assertForbidden();
});

it('convierte ReglaNegocio en 422 con mensaje', function () {
    $this->getJson('/api/_regla')->assertStatus(422)->assertJsonPath('message', 'No se puede.');
});

it('convierte AccionNoPermitida en 403 con mensaje', function () {
    $this->getJson('/api/_prohibido')->assertForbidden()->assertJsonPath('message', 'Prohibido.');
});
