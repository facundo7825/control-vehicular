<?php

use App\Enums\EstadoViaje;
use App\Jobs\AlertarReservaSinTurno;
use App\Jobs\RecordarReserva;
use App\Jobs\VencerOferta;
use App\Models\PuntoRecorrido;
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
    $this->app->instance(Notificador::class, new NotificadorFalso);
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

it('trae pedido, km y datos de cancelación en el detalle', function () {
    $chofer = choferEnTurno();
    $viaje = viajeParaDetalle($chofer, EstadoViaje::Cancelado);
    $viaje->update(['cancelado_por' => 'admin', 'motivo_cancelacion' => 'Sin combustible', 'metros_recorridos' => 4200]);

    $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}")
        ->assertOk()
        ->assertJsonPath('pedido_en', $viaje->created_at->toIso8601String())
        ->assertJsonPath('cancelado_por', 'admin')
        ->assertJsonPath('motivo_cancelacion', 'Sin combustible')
        ->assertJsonPath('metros_recorridos', 4200);
});

function puntosDeRecorrido(Viaje $viaje, int $cantidad): void
{
    $base = now()->subHours(2)->startOfSecond();
    $filas = [];
    for ($i = 0; $i < $cantidad; $i++) {
        $filas[] = ['viaje_id' => $viaje->id, 'lat' => -34.0 - $i / 100000, 'lng' => -58.0, 'registrado_en' => $base->copy()->addSeconds($i)];
    }
    foreach (array_chunk($filas, 100) as $lote) {
        PuntoRecorrido::insert($lote);
    }
}

it('devuelve el recorrido ordenado por fecha', function () {
    $chofer = choferEnTurno();
    $viaje = viajeParaDetalle($chofer, EstadoViaje::Finalizado);
    $viaje->update(['finalizado_en' => now()]);
    $t = now()->subHour();
    PuntoRecorrido::create(['viaje_id' => $viaje->id, 'lat' => 3, 'lng' => 30, 'registrado_en' => $t->copy()->addMinutes(2)]);
    PuntoRecorrido::create(['viaje_id' => $viaje->id, 'lat' => 1, 'lng' => 10, 'registrado_en' => $t]);
    PuntoRecorrido::create(['viaje_id' => $viaje->id, 'lat' => 2, 'lng' => 20, 'registrado_en' => $t->copy()->addMinute()]);

    $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/recorrido")
        ->assertOk()
        ->assertExactJson(['puntos' => [[1, 10], [2, 20], [3, 30]], 'disponible' => true, 'vencido' => false, 'retencion_dias' => 90]);
});

it('reduce a 500 puntos conservando el primero y el último', function () {
    $chofer = choferEnTurno();
    $viaje = viajeParaDetalle($chofer);
    puntosDeRecorrido($viaje, 1200);

    $puntos = $this->actingAs($chofer)->getJson("/api/viajes/{$viaje->id}/recorrido")
        ->assertOk()->json('puntos');

    expect($puntos)->toHaveCount(500)
        ->and($puntos[0][0])->toEqualWithDelta(-34.0, 1e-9)
        ->and($puntos[499][0])->toEqualWithDelta(-34.0 - 1199 / 100000, 1e-9);
});

it('no reduce un recorrido de hasta 500 puntos', function () {
    $viaje = viajeParaDetalle(choferEnTurno());
    puntosDeRecorrido($viaje, 500);

    $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/recorrido")
        ->assertJsonCount(500, 'puntos');
});

it('el admin y el chofer ven el recorrido y un tercero recibe 403', function () {
    $chofer = choferEnTurno();
    $viaje = viajeParaDetalle($chofer);
    puntosDeRecorrido($viaje, 3);

    foreach ([$chofer, Usuario::factory()->admin()->create()] as $usuario) {
        $this->actingAs($usuario)->getJson("/api/viajes/{$viaje->id}/recorrido")
            ->assertOk()->assertJsonPath('disponible', true);
    }
    $this->actingAs(Usuario::factory()->create())->getJson("/api/viajes/{$viaje->id}/recorrido")
        ->assertForbidden()
        ->assertExactJson(['message' => 'Este viaje no es tuyo.']);
});

it('reduce a 500 puntos el caso más chico (501) conservando el primero y el último', function () {
    $viaje = viajeParaDetalle(choferEnTurno());
    puntosDeRecorrido($viaje, 501);

    $puntos = $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/recorrido")
        ->assertOk()->json('puntos');

    expect($puntos)->toHaveCount(500)
        ->and($puntos[0][0])->toEqualWithDelta(-34.0, 1e-9)
        ->and($puntos[499][0])->toEqualWithDelta(-34.0 - 500 / 100000, 1e-9);
});

it('sin puntos no está disponible pero tampoco vencido', function () {
    $viaje = viajeParaDetalle(choferEnTurno(), EstadoViaje::Finalizado);
    $viaje->update(['finalizado_en' => now()]);

    $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/recorrido")
        ->assertOk()->assertExactJson(['puntos' => [], 'disponible' => false, 'vencido' => false, 'retencion_dias' => 90]);
});

it('marca el recorrido vencido pasada la retención', function () {
    $viaje = viajeParaDetalle(choferEnTurno(), EstadoViaje::Finalizado);
    puntosDeRecorrido($viaje, 3);
    $viaje->update(['finalizado_en' => now()->subDays(91)]);

    $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/recorrido")
        ->assertOk()->assertExactJson(['puntos' => [], 'disponible' => false, 'vencido' => true, 'retencion_dias' => 90]);
});

it('aplica la retención también a un viaje cancelado', function () {
    $viaje = viajeParaDetalle(choferEnTurno(), EstadoViaje::Cancelado);
    puntosDeRecorrido($viaje, 3);
    $viaje->update(['cancelado_en' => now()->subDays(91)]);

    $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}/recorrido")
        ->assertOk()->assertExactJson(['puntos' => [], 'disponible' => false, 'vencido' => true, 'retencion_dias' => 90]);
});

it('exige sesión para el recorrido', function () {
    $this->getJson('/api/viajes/1/recorrido')->assertUnauthorized();
});
