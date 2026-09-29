<?php

use App\Enums\EstadoViaje;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;

it('inicia un turno con un vehículo libre', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $vehiculo = Vehiculo::factory()->create();

    $this->actingAs($chofer)->postJson('/api/turnos', ['vehiculo_id' => $vehiculo->id])
        ->assertCreated()
        ->assertJsonPath('vehiculo.patente', $vehiculo->patente);

    expect($chofer->turnoAbierto)->not->toBeNull();
});

it('no permite dos turnos abiertos para el mismo chofer', function () {
    $turno = Turno::factory()->create();

    $this->actingAs($turno->chofer)
        ->postJson('/api/turnos', ['vehiculo_id' => Vehiculo::factory()->create()->id])
        ->assertStatus(422);
});

it('no permite usar un vehículo que está en otro turno abierto', function () {
    $turno = Turno::factory()->create();

    $this->actingAs(Usuario::factory()->chofer()->create())
        ->postJson('/api/turnos', ['vehiculo_id' => $turno->vehiculo_id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'El vehículo está en uso por otro chofer.');
});

it('no permite usar un vehículo inactivo', function () {
    $this->actingAs(Usuario::factory()->chofer()->create())
        ->postJson('/api/turnos', ['vehiculo_id' => Vehiculo::factory()->create(['activo' => false])->id])
        ->assertStatus(422);
});

it('rechaza a un solicitante', function () {
    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/turnos', ['vehiculo_id' => Vehiculo::factory()->create()->id])
        ->assertForbidden();
});

it('lista solo vehículos activos y libres', function () {
    $libre = Vehiculo::factory()->create(['patente' => 'AA111AA']);
    Vehiculo::factory()->create(['activo' => false]);
    Turno::factory()->create();

    $this->actingAs(Usuario::factory()->chofer()->create())
        ->getJson('/api/vehiculos/disponibles')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $libre->id);
});

it('finaliza el turno y borra la última ubicación', function () {
    $turno = Turno::factory()->create();
    UbicacionChofer::create(['chofer_id' => $turno->chofer_id, 'lat' => -34.6, 'lng' => -58.4, 'actualizado_en' => now()]);

    $this->actingAs($turno->chofer)->postJson('/api/turnos/actual/finalizar')->assertOk();

    expect($turno->fresh()->fin)->not->toBeNull()
        ->and(UbicacionChofer::find($turno->chofer_id))->toBeNull();
});

it('no finaliza el turno con un viaje activo', function () {
    $turno = Turno::factory()->create();
    Viaje::factory()->create(['chofer_id' => $turno->chofer_id, 'estado' => EstadoViaje::EnCurso]);

    $this->actingAs($turno->chofer)->postJson('/api/turnos/actual/finalizar')->assertStatus(422);
});

it('devuelve el turno actual o null', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $this->actingAs($chofer)->getJson('/api/turnos/actual')->assertOk()->assertExactJson(['turno' => null]);

    $turno = Turno::factory()->for($chofer, 'chofer')->create();
    $this->actingAs($chofer)->getJson('/api/turnos/actual')->assertJsonPath('turno.id', $turno->id);
});
