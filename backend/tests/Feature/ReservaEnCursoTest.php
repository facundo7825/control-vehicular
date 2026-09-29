<?php

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\CalculadorEstadoChofer;
use App\Servicios\Despachador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
});

it('el chofer sale hacia la reserva desde 45 minutos antes con el vehículo de su turno', function () {
    $chofer = choferEnTurno();
    $reserva = reservaAceptada($chofer, now()->addMinutes(45));

    $this->actingAs($chofer)->postJson("/api/viajes/{$reserva->id}/estado", ['estado' => 'en_camino'])
        ->assertOk()
        ->assertJsonPath('estado', 'en_camino')
        ->assertJsonPath('vehiculo.patente', $chofer->turnoAbierto->vehiculo->patente);

    expect(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::EnViaje);
});

it('no puede salir hacia la reserva antes de tiempo', function () {
    $chofer = choferEnTurno();
    $reserva = reservaAceptada($chofer, now()->addMinutes(46));

    $this->actingAs($chofer)->postJson("/api/viajes/{$reserva->id}/estado", ['estado' => 'en_camino'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Podés salir hacia esta reserva a partir de las 09:01.');

    expect($reserva->fresh()->estado)->toBe(EstadoViaje::Aceptado);
});

it('no puede salir hacia la reserva sin turno abierto', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $reserva = reservaAceptada($chofer, now()->addMinutes(30));

    $this->actingAs($chofer)->postJson("/api/viajes/{$reserva->id}/estado", ['estado' => 'en_camino'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Iniciá tu turno para comenzar la reserva.');

    expect($reserva->fresh()->estado)->toBe(EstadoViaje::Aceptado);
});

it('no puede salir hacia la reserva con otro viaje activo', function () {
    $chofer = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::EnCurso]);
    $reserva = reservaAceptada($chofer, now()->addMinutes(30));

    $this->actingAs($chofer)->postJson("/api/viajes/{$reserva->id}/estado", ['estado' => 'en_camino'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Terminá tu viaje actual antes de comenzar la reserva.');

    expect($reserva->fresh()->estado)->toBe(EstadoViaje::Aceptado);
});

it('repetir en_camino en una reserva que ya salió no falla', function () {
    $chofer = choferEnTurno();
    $reserva = reservaAceptada($chofer, now()->addMinutes(30), attrs: ['estado' => EstadoViaje::EnCamino]);

    $this->actingAs($chofer)->postJson("/api/viajes/{$reserva->id}/estado", ['estado' => 'en_camino'])
        ->assertOk()
        ->assertJsonPath('estado', 'en_camino');
});

it('el chofer cancela una reserva aceptada: queda sin chofer y no se reasigna', function () {
    choferEnTurno(); // libre: no debe recibir nada
    $chofer = choferEnTurno(-34.9, -58.9);
    $reserva = reservaAceptada($chofer, now()->addDay());

    $this->actingAs($chofer)->postJson("/api/viajes/{$reserva->id}/cancelar", ['motivo' => 'Turno médico'])
        ->assertOk()
        ->assertJsonPath('estado', 'sin_chofer')
        ->assertJsonPath('chofer', null);

    $oferta = OfertaViaje::sole();
    expect($oferta->chofer_id)->toBe($chofer->id)
        ->and($oferta->resultado)->toBe(ResultadoOferta::Rechazada)
        ->and($oferta->motivo)->toBe('Turno médico');
});

it('el chofer no puede cancelar una reserva que ya comenzó', function () {
    $chofer = choferEnTurno();
    $reserva = reservaAceptada($chofer, now()->addMinutes(30), attrs: ['estado' => EstadoViaje::EnCamino]);

    $this->actingAs($chofer)->postJson("/api/viajes/{$reserva->id}/cancelar", ['motivo' => 'Me demoré'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'La reserva ya comenzó; no se puede cancelar.');

    expect($reserva->fresh()->estado)->toBe(EstadoViaje::EnCamino)
        ->and(OfertaViaje::count())->toBe(0);
});

it('el chofer no puede cancelar una reserva obligatoria', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $reserva = reservaAceptada($chofer, now()->addDay(), attrs: ['obligatorio' => true]);

    $this->actingAs($chofer)->postJson("/api/viajes/{$reserva->id}/cancelar", ['motivo' => 'x'])
        ->assertForbidden();

    expect($reserva->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($reserva->fresh()->chofer_id)->toBe($chofer->id);
});

it('viajes/actual del chofer no muestra solicitudes de reserva', function () {
    $chofer = choferEnTurno();
    app(Despachador::class)->ofrecerReserva(reservaBuscando(), $chofer);

    $this->actingAs($chofer)->getJson('/api/viajes/actual')
        ->assertOk()
        ->assertJsonPath('viaje', null)
        ->assertJsonPath('oferta', null);
});

it('viajes/actual del solicitante muestra su reserva recién cuando comenzó', function () {
    $reserva = reservaAceptada(choferEnTurno(), now()->addDay());

    $this->actingAs($reserva->solicitante)->getJson('/api/viajes/actual')->assertJsonPath('viaje', null);

    $reserva->update(['estado' => EstadoViaje::EnCamino]);

    $this->actingAs($reserva->solicitante)->getJson('/api/viajes/actual')->assertJsonPath('viaje.id', $reserva->id);
});
