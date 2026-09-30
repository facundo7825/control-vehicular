<?php

use App\Models\Turno;
use App\Models\Usuario;

it('devuelve los errores de un viaje sin datos en español con atributos legibles', function () {
    $r = $this->actingAs(Usuario::factory()->create())->postJson('/api/viajes', []);

    $r->assertStatus(422)
        ->assertJsonPath('errors.origen_lat.0', 'El campo latitud de origen es obligatorio.')
        ->assertJsonPath('errors.destino_lng.0', 'El campo longitud de destino es obligatorio.')
        ->assertJsonPath('errors.modo.0', 'El campo modo es obligatorio.');
    expect($r->json('message'))->toContain('obligatorio')->not->toContain('required');
});

it('traduce el error de una fecha programada inválida', function () {
    $r = $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/reservas', ['programado_para' => 'no-es-fecha']);

    $r->assertStatus(422)
        ->assertJsonPath('errors.programado_para.0', 'El campo fecha y hora programada debe ser una fecha válida.');
});

it('traduce los errores de los puntos de ubicación', function () {
    $turno = Turno::factory()->create();

    $r = $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => 120, 'lng' => -58.39, 'registrado_en' => now()->toIso8601String()],
    ]]);

    $r->assertStatus(422);
    expect($r->json('errors')['puntos.0.lat'][0])->toBe('El campo latitud del punto debe estar entre -90 y 90.');
});

it('traduce el error de falta de token externo en el intercambio', function () {
    $this->postJson('/api/auth/intercambio', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.token_externo.0', 'El campo token externo es obligatorio.');
});
