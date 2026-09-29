<?php

use App\Models\Usuario;
use App\Models\Viaje;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'clave',
        'broadcasting.connections.reverb.secret' => 'secreto',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);

    // Los canales se registraron al arrancar sobre el driver de phpunit.xml (null);
    // se vuelven a registrar sobre el driver reverb recién configurado.
    require base_path('routes/channels.php');
});

function autorizarCanal(Usuario $u, string $canal)
{
    return test()->actingAs($u)->postJson('/api/broadcasting/auth', [
        'socket_id' => '1234.5678', 'channel_name' => "private-$canal",
    ]);
}

it('el solicitante accede al canal de su viaje y un tercero no', function () {
    $viaje = Viaje::factory()->create();

    autorizarCanal($viaje->solicitante, "viaje.{$viaje->id}")->assertOk();
    autorizarCanal(Usuario::factory()->create(), "viaje.{$viaje->id}")->assertForbidden();
});

it('el chofer asignado accede al canal del viaje', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id]);

    autorizarCanal($chofer, "viaje.{$viaje->id}")->assertOk();
});

it('solo el propio chofer accede a su canal personal', function () {
    $chofer = Usuario::factory()->chofer()->create();

    autorizarCanal($chofer, "chofer.{$chofer->id}")->assertOk();
    autorizarCanal(Usuario::factory()->chofer()->create(), "chofer.{$chofer->id}")->assertForbidden();
});

it('cualquier usuario activo accede al mapa', function () {
    autorizarCanal(Usuario::factory()->create(), 'mapa.choferes')->assertOk();
});
