<?php

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\CalculadorEstadoChofer;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function viajeAsignado(Usuario $chofer, array $attrs = []): Viaje
{
    return Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'vehiculo_id' => $chofer->turnoAbierto->vehiculo_id,
        'estado' => EstadoViaje::Aceptado,
        'aceptado_en' => now(),
        ...$attrs,
    ]);
}

it('recorre el viaje completo y libera al chofer', function () {
    $chofer = choferEnTurno();
    $viaje = viajeAsignado($chofer);

    foreach (['en_camino', 'llego', 'en_curso', 'finalizado'] as $estado) {
        $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => $estado])
            ->assertOk()
            ->assertJsonPath('estado', $estado);
    }

    expect($viaje->fresh()->finalizado_en)->not->toBeNull()
        ->and(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::Libre);
});

it('no permite saltear pasos', function () {
    $chofer = choferEnTurno();
    $viaje = viajeAsignado($chofer);

    $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => 'finalizado'])
        ->assertStatus(422);
});

it('repetir un paso no falla ni cambia nada', function () {
    $chofer = choferEnTurno();
    $viaje = viajeAsignado($chofer, ['estado' => EstadoViaje::Llego, 'llego_en' => now()->subMinute()]);

    $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => 'llego'])->assertOk();

    expect($viaje->fresh()->llego_en->lt(now()->subSeconds(30)))->toBeTrue();
});

it('otro chofer no puede avanzar el viaje', function () {
    $viaje = viajeAsignado(choferEnTurno());

    $this->actingAs(choferEnTurno(-34.9, -58.9))
        ->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => 'en_camino'])
        ->assertForbidden();
});

it('el solicitante cancela antes de iniciar el viaje', function () {
    $viaje = viajeAsignado(choferEnTurno(), ['estado' => EstadoViaje::EnCamino]);

    $this->actingAs($viaje->solicitante)
        ->postJson("/api/viajes/{$viaje->id}/cancelar", ['motivo' => 'Ya no lo necesito'])
        ->assertOk()
        ->assertJsonPath('estado', 'cancelado');

    expect($viaje->fresh()->cancelado_por)->toBe('solicitante')
        ->and($viaje->fresh()->motivo_cancelacion)->toBe('Ya no lo necesito');
});

it('el solicitante no puede cancelar un viaje en curso', function () {
    $viaje = viajeAsignado(choferEnTurno(), ['estado' => EstadoViaje::EnCurso]);

    $this->actingAs($viaje->solicitante)->postJson("/api/viajes/{$viaje->id}/cancelar")->assertStatus(422);
});

it('otro usuario no puede cancelar el viaje', function () {
    $viaje = viajeAsignado(choferEnTurno());

    $this->actingAs(Usuario::factory()->create())->postJson("/api/viajes/{$viaje->id}/cancelar")->assertForbidden();
});

it('cancelar mientras se ofrece vence la oferta pendiente', function () {
    $chofer = choferEnTurno();
    $solicitante = Usuario::factory()->create();
    $id = $this->actingAs($solicitante)->postJson('/api/viajes', [
        'modo' => 'mas_cercano', 'origen_lat' => -34.6, 'origen_lng' => -58.38,
        'destino_lat' => -34.61, 'destino_lng' => -58.39,
    ])->json('id');

    $this->actingAs($solicitante)->postJson("/api/viajes/{$id}/cancelar")->assertOk();

    expect(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Expirada);
});

it('el chofer cancela un viaje no obligatorio y se reasigna a otro', function () {
    $a = choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.620, -58.400);
    $viaje = viajeAsignado($a, ['origen_lat' => -34.600, 'origen_lng' => -58.380]);

    $this->actingAs($a)->postJson("/api/viajes/{$viaje->id}/cancelar", ['motivo' => 'Pinchadura'])
        ->assertOk()
        ->assertJsonPath('estado', 'ofrecido');

    expect(OfertaViaje::where('chofer_id', $a->id)->sole()->motivo)->toBe('Pinchadura')
        ->and(OfertaViaje::where('resultado', ResultadoOferta::Pendiente)->sole()->chofer_id)->toBe($b->id)
        ->and($viaje->fresh()->chofer_id)->toBeNull();
});

it('el chofer no puede cancelar un viaje obligatorio', function () {
    $chofer = choferEnTurno();
    $viaje = viajeAsignado($chofer, ['obligatorio' => true]);

    $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/cancelar", ['motivo' => 'x'])
        ->assertForbidden();

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($viaje->fresh()->chofer_id)->toBe($chofer->id);
});

it('el chofer debe indicar un motivo para cancelar', function () {
    $chofer = choferEnTurno();
    $viaje = viajeAsignado($chofer);

    $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/cancelar")->assertJsonValidationErrors('motivo');
});
