<?php

use App\Enums\EstadoViaje;
use App\Jobs\AlertarReservaSinTurno;
use App\Jobs\RecordarReserva;
use App\Jobs\VencerOferta;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Facades\Bus;
use Tests\Fakes\NotificadorFalso;

function viajeParaDetalle(Usuario $chofer, EstadoViaje $estado = EstadoViaje::EnCurso): Viaje
{
    return Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'vehiculo_id' => $chofer->turnoAbierto?->vehiculo_id,
        'estado' => $estado,
        'aceptado_en' => now()->subMinutes(10),
    ]);
}

it('muestra el detalle al solicitante, al chofer actual y a un admin', function () {
    $chofer = choferEnTurno();
    $viaje = viajeParaDetalle($chofer);
    $admin = Usuario::factory()->admin()->create();

    foreach ([$viaje->solicitante, $chofer, $admin] as $usuario) {
        $this->actingAs($usuario)->getJson("/api/viajes/{$viaje->id}")
            ->assertOk()
            ->assertJsonPath('id', $viaje->id)
            ->assertJsonPath('estado', 'en_curso')
            ->assertJsonPath('chofer.id', $chofer->id);
    }
});

it('rechaza con 403 a otro usuario', function () {
    $viaje = viajeParaDetalle(choferEnTurno());

    $this->actingAs(Usuario::factory()->create())->getJson("/api/viajes/{$viaje->id}")
        ->assertForbidden()
        ->assertExactJson(['message' => 'Este viaje no es tuyo.']);
});

it('rechaza con 403 al chofer anterior tras una reasignación del admin', function () {
    Bus::fake([VencerOferta::class, RecordarReserva::class, AlertarReservaSinTurno::class]);
    $this->app->instance(Notificador::class, new NotificadorFalso());
    $anterior = choferEnTurno();
    $nuevo = choferEnTurno();
    $viaje = viajeParaDetalle($anterior, EstadoViaje::Aceptado);

    app(ServicioViaje::class)->reasignarPorAdmin($viaje, $nuevo);

    $this->actingAs($anterior)->getJson("/api/viajes/{$viaje->id}")
        ->assertForbidden()
        ->assertExactJson(['message' => 'Este viaje no es tuyo.']);
    $this->actingAs($nuevo)->getJson("/api/viajes/{$viaje->id}")
        ->assertOk()
        ->assertJsonPath('chofer.id', $nuevo->id);
});

it('sigue mostrando a su chofer un viaje cancelado', function () {
    $chofer = choferEnTurno();
    $viaje = viajeParaDetalle($chofer, EstadoViaje::Cancelado);

    $this->actingAs($chofer)->getJson("/api/viajes/{$viaje->id}")
        ->assertOk()
        ->assertJsonPath('estado', 'cancelado')
        ->assertJsonPath('chofer.id', $chofer->id);
});

it('no choca con /viajes/actual', function () {
    $this->actingAs(Usuario::factory()->create())->getJson('/api/viajes/actual')
        ->assertOk()
        ->assertJsonPath('viaje', null);
});

it('exige sesión', function () {
    $this->getJson('/api/viajes/1')->assertUnauthorized();
});
