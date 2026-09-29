<?php

use App\Enums\EstadoViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\Despachador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
});

it('la agenda del chofer muestra sus reservas confirmadas y sus solicitudes pendientes', function () {
    $chofer = choferEnTurno();
    $pasadoManana = reservaAceptada($chofer, now()->addDays(2));
    $manana = reservaAceptada($chofer, now()->addDay());
    reservaAceptada($chofer, now()->subDay(), attrs: ['estado' => EstadoViaje::Finalizado]);
    reservaAceptada(Usuario::factory()->chofer()->create(), now()->addDay()->addHours(3)); // de otro chofer
    $d = app(Despachador::class);
    $d->ofrecerReserva(reservaBuscando(['programado_para' => now()->addDays(3)]), $chofer);
    $d->despachar(Viaje::factory()->create()); // oferta inmediata: va en viajes/actual, no en la agenda

    $this->actingAs($chofer)->getJson('/api/agenda')
        ->assertOk()
        ->assertJsonCount(2, 'reservas')
        ->assertJsonPath('reservas.0.id', $manana->id)
        ->assertJsonPath('reservas.1.id', $pasadoManana->id)
        ->assertJsonCount(1, 'solicitudes')
        ->assertJsonPath('solicitudes.0.vence_en', now()->addMinutes(30)->toIso8601String())
        ->assertJsonPath('solicitudes.0.viaje.tipo', 'reserva');
});

it('la agenda es solo para choferes', function () {
    $this->actingAs(Usuario::factory()->create())->getJson('/api/agenda')->assertForbidden();
});

it('mis viajes separa las próximas reservas del historial', function () {
    $s = Usuario::factory()->create();
    $aceptada = reservaAceptada(Usuario::factory()->chofer()->create(), now()->addDays(2), attrs: ['solicitante_id' => $s->id]);
    $ofrecida = reservaBuscando([
        'solicitante_id' => $s->id, 'estado' => EstadoViaje::Ofrecido, 'programado_para' => now()->addDay(),
    ]);
    $finalizado = Viaje::factory()->for($s, 'solicitante')->create(['estado' => EstadoViaje::Finalizado]);
    $cancelada = reservaBuscando(['solicitante_id' => $s->id, 'estado' => EstadoViaje::Cancelado]);
    Viaje::factory()->for($s, 'solicitante')->create(['estado' => EstadoViaje::EnCurso]); // inmediato: va en viajes/actual
    Viaje::factory()->create(['estado' => EstadoViaje::Finalizado]); // de otro solicitante

    $this->actingAs($s)->getJson('/api/viajes')
        ->assertOk()
        ->assertJsonCount(2, 'proximas')
        ->assertJsonPath('proximas.0.id', $ofrecida->id)
        ->assertJsonPath('proximas.1.id', $aceptada->id)
        ->assertJsonCount(2, 'historial')
        ->assertJsonPath('historial.0.id', $cancelada->id)
        ->assertJsonPath('historial.1.id', $finalizado->id);
});

it('el historial muestra los últimos 50 viajes', function () {
    $s = Usuario::factory()->create();
    Viaje::factory()->count(55)->for($s, 'solicitante')->create(['estado' => EstadoViaje::Finalizado]);

    $this->actingAs($s)->getJson('/api/viajes')
        ->assertOk()
        ->assertJsonCount(50, 'historial')
        ->assertJsonPath('historial.0.id', Viaje::max('id'));
});
