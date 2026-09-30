<?php

use App\Enums\EstadoViaje;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;

function viajeConChofer(EstadoViaje $estado, Usuario $chofer, array $attrs = []): Viaje
{
    return Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'estado' => $estado,
        ...$attrs,
    ]);
}

it('informa el ETA hacia el origen cuando el chofer va en camino', function () {
    // ~1 km al norte del origen (-34.6037, -58.3816)
    $chofer = choferEnTurno(-34.5947, -58.3816);
    $viaje = viajeConChofer(EstadoViaje::EnCamino, $chofer);

    $r = $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/eta")
        ->assertOk()
        ->assertJsonPath('hacia', 'origen');

    expect($r->json('metros'))->toBeBetween(900, 1100)
        ->and($r->json('segundos'))->toBe((int) round($r->json('metros') / 8.33))
        ->and($r->json('calculado_en'))->toBeString();
});

it('calcula hacia el destino cuando el viaje está en curso', function () {
    $chofer = choferEnTurno(-34.6037, -58.3816);
    $viaje = viajeConChofer(EstadoViaje::EnCurso, $chofer);

    $r = $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/eta")
        ->assertOk()
        ->assertJsonPath('hacia', 'destino');

    $esperado = App\Mapas\Distancia::metros(-34.6037, -58.3816, -34.6090, -58.3920);
    expect($r->json('metros'))->toBe((int) round($esperado));
});

it('devuelve cero cuando el chofer ya llegó', function () {
    $chofer = choferEnTurno(-34.50, -58.30);
    $viaje = viajeConChofer(EstadoViaje::Llego, $chofer);

    $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/eta")
        ->assertOk()
        ->assertJsonPath('hacia', 'origen')
        ->assertJsonPath('segundos', 0)
        ->assertJsonPath('metros', 0);
});

it('devuelve null si el chofer no tiene ubicación', function () {
    $chofer = choferEnTurno();
    UbicacionChofer::where('chofer_id', $chofer->id)->delete();
    $viaje = viajeConChofer(EstadoViaje::EnCamino, $chofer);

    $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/eta")
        ->assertOk()
        ->assertJsonPath('segundos', null)
        ->assertJsonPath('metros', null);
});

it('rechaza viajes sin chofer en camino', function (EstadoViaje $estado) {
    $viaje = Viaje::factory()->create(['estado' => $estado]);

    $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/eta")
        ->assertStatus(422)
        ->assertExactJson(['message' => 'El viaje no tiene un chofer en camino.']);
})->with([EstadoViaje::Buscando, EstadoViaje::Finalizado]);

it('restringe el acceso al solicitante, el chofer asignado y los admin', function () {
    $chofer = choferEnTurno();
    $viaje = viajeConChofer(EstadoViaje::EnCamino, $chofer);

    $this->actingAs(Usuario::factory()->create())->getJson("/api/viajes/{$viaje->id}/eta")
        ->assertForbidden()
        ->assertExactJson(['message' => 'Este viaje no es tuyo.']);

    $this->actingAs($chofer)->getJson("/api/viajes/{$viaje->id}/eta")->assertOk();
    $this->actingAs(Usuario::factory()->admin()->create())->getJson("/api/viajes/{$viaje->id}/eta")->assertOk();
});

it('cachea el cálculo 30 segundos', function () {
    $this->travelTo(now()->startOfSecond());
    $chofer = choferEnTurno();
    $viaje = viajeConChofer(EstadoViaje::EnCamino, $chofer);

    $a = $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/eta")->json('calculado_en');
    $this->travel(10)->seconds();
    $b = $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/eta")->json('calculado_en');
    expect($b)->toBe($a);

    $this->travel(31)->seconds();
    $c = $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/eta")->json('calculado_en');
    expect($c)->not->toBe($a);
});
