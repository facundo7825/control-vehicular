<?php

use App\Enums\EstadoViaje;
use App\Models\CargoPrioritario;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function datosPedido(array $extra = []): array
{
    return [
        'modo' => 'mas_cercano',
        'origen_lat' => -34.600, 'origen_lng' => -58.380, 'origen_direccion' => 'Talcahuano 550',
        'destino_lat' => -34.609, 'destino_lng' => -58.392, 'destino_direccion' => 'Tribunales',
        'motivo' => 'Audiencia',
        ...$extra,
    ];
}

it('crea un pedido al más cercano y lo ofrece', function () {
    $chofer = choferEnTurno(-34.601, -58.381);

    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/viajes', datosPedido())
        ->assertCreated()
        ->assertJsonPath('estado', 'ofrecido')
        ->assertJsonPath('obligatorio', false)
        ->assertJsonPath('destino.direccion', 'Tribunales');

    expect(OfertaViaje::sole()->chofer_id)->toBe($chofer->id);
});

it('marca obligatorio y asigna directo si el cargo es prioritario', function () {
    CargoPrioritario::create(['cargo' => 'Juez', 'obligatorio' => true]);
    $chofer = choferEnTurno();

    $this->actingAs(Usuario::factory()->create(['cargo' => 'Juez']))
        ->postJson('/api/viajes', datosPedido())
        ->assertCreated()
        ->assertJsonPath('estado', 'aceptado')
        ->assertJsonPath('obligatorio', true)
        ->assertJsonPath('chofer.id', $chofer->id)
        ->assertJsonPath('vehiculo.patente', $chofer->turnoAbierto->vehiculo->patente);
});

it('rechaza pedir un chofer específico que no está libre', function () {
    $ocupado = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $ocupado->id, 'estado' => EstadoViaje::EnCurso]);

    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/viajes', datosPedido(['modo' => 'especifico', 'chofer_id' => $ocupado->id]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'El chofer elegido no está disponible.');

    expect(Viaje::count())->toBe(1);
});

it('exige chofer_id en modo específico', function () {
    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/viajes', datosPedido(['modo' => 'especifico']))
        ->assertJsonValidationErrors('chofer_id');
});

it('no permite un segundo pedido con uno en progreso', function () {
    $solicitante = Usuario::factory()->create();
    Viaje::factory()->for($solicitante, 'solicitante')->create(['estado' => EstadoViaje::Ofrecido]);

    $this->actingAs($solicitante)->postJson('/api/viajes', datosPedido())->assertStatus(422);
});

it('no permite pedir a un chofer', function () {
    $this->actingAs(Usuario::factory()->chofer()->create())
        ->postJson('/api/viajes', datosPedido())
        ->assertForbidden();
});

it('el chofer acepta su oferta', function () {
    $chofer = choferEnTurno();
    $this->actingAs(Usuario::factory()->create())->postJson('/api/viajes', datosPedido());
    $oferta = OfertaViaje::sole();

    $this->actingAs($chofer)->postJson("/api/ofertas/{$oferta->id}/aceptar")
        ->assertOk()
        ->assertJsonPath('estado', 'aceptado')
        ->assertJsonPath('chofer.id', $chofer->id);
});

it('el chofer no puede responder una oferta ajena', function () {
    choferEnTurno();
    $otro = choferEnTurno(-34.9, -58.9);
    $this->actingAs(Usuario::factory()->create())->postJson('/api/viajes', datosPedido());

    $this->actingAs($otro)->postJson('/api/ofertas/'.OfertaViaje::sole()->id.'/aceptar')->assertForbidden();
});

it('el chofer rechaza y el viaje queda sin chofer si no hay otro', function () {
    $chofer = choferEnTurno();
    $viajeId = $this->actingAs(Usuario::factory()->create())->postJson('/api/viajes', datosPedido())->json('id');

    $this->actingAs($chofer)->postJson('/api/ofertas/'.OfertaViaje::sole()->id.'/rechazar')->assertNoContent();

    expect(Viaje::find($viajeId)->estado)->toBe(EstadoViaje::SinChofer);
});

it('muestra al chofer su oferta pendiente en viajes/actual', function () {
    $chofer = choferEnTurno();
    $this->actingAs(Usuario::factory()->create())->postJson('/api/viajes', datosPedido());

    $this->actingAs($chofer)->getJson('/api/viajes/actual')
        ->assertOk()
        ->assertJsonPath('viaje', null)
        ->assertJsonPath('oferta.id', OfertaViaje::sole()->id)
        ->assertJsonPath('oferta.viaje.motivo', 'Audiencia');
});

it('muestra al solicitante su viaje en progreso', function () {
    choferEnTurno();
    $solicitante = Usuario::factory()->create();
    $id = $this->actingAs($solicitante)->postJson('/api/viajes', datosPedido())->json('id');

    $this->actingAs($solicitante)->getJson('/api/viajes/actual')->assertJsonPath('viaje.id', $id);
});

it('lista los choferes en turno para el mapa', function () {
    $libre = choferEnTurno(-34.601, -58.381);
    Usuario::factory()->chofer()->create();

    $this->actingAs(Usuario::factory()->create())->getJson('/api/choferes')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $libre->id)
        ->assertJsonPath('0.estado', 'libre')
        ->assertJsonPath('0.lat', -34.601)
        ->assertJsonPath('0.vehiculo.patente', $libre->turnoAbierto->vehiculo->patente);
});

it('rechaza pedir un viaje a un chofer desactivado', function () {
    $chofer = choferEnTurno();
    $chofer->update(['activo' => false]);

    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/viajes', datosPedido(['modo' => 'especifico', 'chofer_id' => $chofer->id]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'El chofer elegido no existe.');

    expect(Viaje::count())->toBe(0)
        ->and(OfertaViaje::count())->toBe(0);
});
