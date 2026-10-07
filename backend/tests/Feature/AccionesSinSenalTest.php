<?php

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Mapas\Distancia;
use App\Models\AccionViaje;
use App\Models\PuntoRecorrido;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\ServicioTurnos;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 15:00:00')); // 12:00 en Buenos Aires
});

function viajeSinSenal(Usuario $chofer, array $attrs = []): Viaje
{
    return Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'vehiculo_id' => $chofer->turnoAbierto?->vehiculo_id,
        'estado' => EstadoViaje::Aceptado,
        'aceptado_en' => now()->subHour(),
        ...$attrs,
    ]);
}

function avanzarSinSenal(Usuario $chofer, Viaje $viaje, string $estado, ?string $momento, ?string $idAccion = null)
{
    return test()->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", array_filter([
        'estado' => $estado, 'momento' => $momento, 'id_accion' => $idAccion ?? (string) Str::uuid(),
    ]));
}

describe('avance con la hora real', function () {
    it('guarda los timestamps con el momento en que el chofer tocó cada botón', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer);

        avanzarSinSenal($chofer, $viaje, 'en_camino', now()->subMinutes(50)->toIso8601String())->assertOk();
        avanzarSinSenal($chofer, $viaje, 'llego', now()->subMinutes(40)->toIso8601String())->assertOk();
        // Sin offset: hora de Buenos Aires.
        avanzarSinSenal($chofer, $viaje, 'en_curso', '2026-10-01T11:25:00')->assertOk();
        avanzarSinSenal($chofer, $viaje, 'finalizado', now()->subMinutes(10)->toIso8601String())
            ->assertOk()
            ->assertJsonPath('estado', 'finalizado');

        $viaje->refresh();
        expect($viaje->llego_en->equalTo(now()->subMinutes(40)))->toBeTrue()
            ->and($viaje->iniciado_en->equalTo(now()->subMinutes(35)))->toBeTrue()
            ->and($viaje->finalizado_en->equalTo(now()->subMinutes(10)))->toBeTrue()
            ->and(AccionViaje::where('viaje_id', $viaje->id)->count())->toBe(4);
    });

    it('sin momento usa la hora del servidor, como siempre', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, ['estado' => EstadoViaje::EnCamino]);

        $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => 'llego'])->assertOk();

        expect($viaje->fresh()->llego_en->equalTo(now()))->toBeTrue()
            ->and(AccionViaje::count())->toBe(0);
    });

    it('rechaza un momento en el futuro', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, ['estado' => EstadoViaje::EnCamino]);

        avanzarSinSenal($chofer, $viaje, 'llego', now()->addMinutes(5)->toIso8601String())
            ->assertStatus(422)
            ->assertJsonPath('message', 'La hora de la acción está en el futuro. Revisá la hora del celular.');

        expect($viaje->fresh()->estado)->toBe(EstadoViaje::EnCamino);
    });

    it('un momento apenas adelantado (el reloj del celular) se toma como ahora', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, ['estado' => EstadoViaje::EnCamino]);

        avanzarSinSenal($chofer, $viaje, 'llego', now()->addMinute()->toIso8601String())->assertOk();

        expect($viaje->fresh()->llego_en->equalTo(now()))->toBeTrue();
    });

    it('rechaza un momento anterior al paso previo del viaje', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, ['estado' => EstadoViaje::Llego, 'llego_en' => now()->subMinutes(30)]);

        avanzarSinSenal($chofer, $viaje, 'en_curso', now()->subMinutes(40)->toIso8601String())
            ->assertStatus(422)
            ->assertJsonPath('message', 'La hora de la acción es anterior al paso anterior del viaje.');

        expect($viaje->fresh()->estado)->toBe(EstadoViaje::Llego);
    });

    it('un momento apenas anterior al paso previo (el reloj del celular atrasado) toma la hora del paso previo', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, ['estado' => EstadoViaje::EnCurso, 'llego_en' => now()->subMinutes(20),
            'iniciado_en' => now()->subMinutes(10)]);

        avanzarSinSenal($chofer, $viaje, 'finalizado', now()->subMinutes(11)->toIso8601String())->assertOk();

        expect($viaje->fresh()->finalizado_en->equalTo(now()->subMinutes(10)))->toBeTrue();
    });

    it('rechaza una acción de hace más de 24 horas', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, ['estado' => EstadoViaje::EnCamino, 'aceptado_en' => now()->subDays(2)]);

        avanzarSinSenal($chofer, $viaje, 'llego', now()->subHours(25)->toIso8601String())
            ->assertStatus(422)
            ->assertJsonPath('message', 'La acción tiene más de 24 horas; ya no se puede registrar.');

        expect($viaje->fresh()->estado)->toBe(EstadoViaje::EnCamino);
    });

    it('una acción reenviada con el mismo id_accion no se aplica dos veces', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, ['estado' => EstadoViaje::EnCamino]);
        $id = (string) Str::uuid();

        avanzarSinSenal($chofer, $viaje, 'llego', now()->subMinutes(5)->toIso8601String(), $id)->assertOk();
        avanzarSinSenal($chofer, $viaje, 'en_curso', now()->subMinutes(3)->toIso8601String())->assertOk();

        // La app no recibió la respuesta del "Llegué" y lo reenvía: devuelve el viaje como está, sin error.
        avanzarSinSenal($chofer, $viaje, 'llego', now()->subMinutes(5)->toIso8601String(), $id)
            ->assertOk()
            ->assertJsonPath('estado', 'en_curso');

        expect($viaje->fresh()->llego_en->equalTo(now()->subMinutes(5)))->toBeTrue()
            ->and(AccionViaje::where('id_accion', $id)->count())->toBe(1);
    });

    it('el id_accion de otro viaje no se acepta', function () {
        $chofer = choferEnTurno();
        $uno = viajeSinSenal($chofer, ['estado' => EstadoViaje::EnCamino]);
        $otro = viajeSinSenal($chofer, ['estado' => EstadoViaje::EnCamino]);
        $id = (string) Str::uuid();
        avanzarSinSenal($chofer, $uno, 'llego', null, $id)->assertOk();

        avanzarSinSenal($chofer, $otro, 'llego', null, $id)->assertStatus(422);

        expect($otro->fresh()->estado)->toBe(EstadoViaje::EnCamino);
    });

    it('si el viaje se canceló mientras estaba sin señal, responde 409 sin cambiar nada', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, ['estado' => EstadoViaje::Cancelado, 'cancelado_en' => now()->subMinutes(3)]);

        avanzarSinSenal($chofer, $viaje, 'llego', now()->subMinutes(5)->toIso8601String())
            ->assertStatus(409)
            ->assertJsonPath('message', 'El viaje fue cancelado mientras estabas sin señal.');

        expect($viaje->fresh()->llego_en)->toBeNull()
            ->and(AccionViaje::count())->toBe(0);
    });

    it('si el viaje se reasignó mientras estaba sin señal, responde 409 sin cambiar nada', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal(choferEnTurno(-34.7, -58.5), ['estado' => EstadoViaje::EnCamino]);

        avanzarSinSenal($chofer, $viaje, 'llego', now()->subMinutes(5)->toIso8601String())
            ->assertStatus(409)
            ->assertJsonPath('message', 'El viaje fue reasignado a otro chofer mientras estabas sin señal.');

        expect($viaje->fresh()->estado)->toBe(EstadoViaje::EnCamino);
    });

    it('sale hacia un viaje largo con la hora en que tocó "Voy en camino"', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, [
            'tipo' => TipoViaje::Largo, 'programado_para' => now()->addMinutes(10), 'duracion_estimada_min' => 600,
        ]);
        $id = (string) Str::uuid();

        avanzarSinSenal($chofer, $viaje, 'en_camino', now()->subMinutes(5)->toIso8601String(), $id)
            ->assertOk()
            ->assertJsonPath('estado', 'en_camino');
        avanzarSinSenal($chofer, $viaje, 'en_camino', now()->subMinutes(5)->toIso8601String(), $id)->assertOk();

        expect(AccionViaje::where('viaje_id', $viaje->id)->sole()->momento->equalTo(now()->subMinutes(5)))->toBeTrue();
    });

    it('no sale hacia una reserva si el momento es antes de tiempo', function () {
        $chofer = choferEnTurno();
        $reserva = reservaAceptada($chofer, now()->addMinutes(46));

        avanzarSinSenal($chofer, $reserva, 'en_camino', now()->subMinute()->toIso8601String())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Podés salir hacia esta reserva a partir de las 12:01.');
    });
});

describe('puntos de GPS atrasados', function () {
    it('asigna los puntos que llegan tarde al viaje ya finalizado y recalcula sus metros', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, [
            'estado' => EstadoViaje::Finalizado, 'iniciado_en' => now()->subMinutes(30), 'finalizado_en' => now()->subMinutes(10),
            'metros_recorridos' => 0,
        ]);

        $this->actingAs($chofer)->postJson('/api/ubicacion', ['puntos' => [
            ['lat' => -34.600, 'lng' => -58.380, 'registrado_en' => now()->subMinutes(40)->toIso8601String()], // antes
            ['lat' => -34.600, 'lng' => -58.380, 'registrado_en' => now()->subMinutes(25)->toIso8601String()],
            ['lat' => -34.610, 'lng' => -58.380, 'registrado_en' => now()->subMinutes(15)->toIso8601String()],
            ['lat' => -34.700, 'lng' => -58.380, 'registrado_en' => now()->subMinutes(5)->toIso8601String()], // después
        ]])->assertNoContent();

        expect(PuntoRecorrido::where('viaje_id', $viaje->id)->count())->toBe(2)
            ->and($viaje->fresh()->metros_recorridos)->toBe((int) round(Distancia::metros(-34.600, -58.380, -34.610, -58.380)));
    });

    it('cada punto va al viaje cuyo intervalo contiene su hora', function () {
        $chofer = choferEnTurno();
        $anterior = viajeSinSenal($chofer, [
            'estado' => EstadoViaje::Finalizado, 'iniciado_en' => now()->subMinutes(60), 'finalizado_en' => now()->subMinutes(40),
        ]);
        $actual = viajeSinSenal($chofer, ['estado' => EstadoViaje::EnCurso, 'iniciado_en' => now()->subMinutes(20)]);

        $this->actingAs($chofer)->postJson('/api/ubicacion', ['puntos' => [
            ['lat' => -34.60, 'lng' => -58.38, 'registrado_en' => now()->subMinutes(50)->toIso8601String()],
            ['lat' => -34.61, 'lng' => -58.38, 'registrado_en' => now()->subMinutes(30)->toIso8601String()], // entre viajes
            ['lat' => -34.62, 'lng' => -58.38, 'registrado_en' => now()->subMinutes(10)->toIso8601String()],
        ]])->assertNoContent();

        expect(PuntoRecorrido::where('viaje_id', $anterior->id)->pluck('lat')->all())->toBe([-34.60])
            ->and(PuntoRecorrido::where('viaje_id', $actual->id)->pluck('lat')->all())->toBe([-34.62]);
    });

    it('sin turno abierto acepta los puntos de un viaje, pero no actualiza la ubicación actual', function () {
        $chofer = choferEnTurno();
        app(ServicioTurnos::class)->finalizar($chofer);
        $viaje = viajeSinSenal($chofer, [
            'estado' => EstadoViaje::Finalizado, 'iniciado_en' => now()->subMinutes(30), 'finalizado_en' => now()->subMinutes(10),
        ]);

        $this->actingAs($chofer)->postJson('/api/ubicacion', ['puntos' => [
            ['lat' => -34.600, 'lng' => -58.380, 'registrado_en' => now()->subMinutes(25)->toIso8601String()],
            ['lat' => -34.610, 'lng' => -58.380, 'registrado_en' => now()->subMinutes(15)->toIso8601String()],
        ]])->assertNoContent();

        expect(PuntoRecorrido::where('viaje_id', $viaje->id)->count())->toBe(2)
            ->and($viaje->fresh()->metros_recorridos)->toBe((int) round(Distancia::metros(-34.600, -58.380, -34.610, -58.380)))
            ->and(UbicacionChofer::find($chofer->id))->toBeNull();
    });

    it('sin turno, si ningún punto es de un viaje, pide iniciar un turno', function () {
        $chofer = Turno::factory()->create(['fin' => now()->subHour()])->chofer;
        viajeSinSenal($chofer, [
            'estado' => EstadoViaje::Finalizado, 'iniciado_en' => now()->subMinutes(30), 'finalizado_en' => now()->subMinutes(10),
        ]);

        $this->actingAs($chofer)->postJson('/api/ubicacion', ['puntos' => [
            ['lat' => -34.60, 'lng' => -58.38, 'registrado_en' => now()->subMinutes(5)->toIso8601String()],
        ]])->assertStatus(422)->assertJsonPath('message', 'Iniciá un turno para compartir tu ubicación.');

        expect(PuntoRecorrido::count())->toBe(0);
    });

    it('descarta los puntos de hace más de 24 horas', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, ['estado' => EstadoViaje::EnCurso, 'iniciado_en' => now()->subHours(30)]);

        $this->actingAs($chofer)->postJson('/api/ubicacion', ['puntos' => [
            ['lat' => -34.60, 'lng' => -58.38, 'registrado_en' => now()->subHours(25)->toIso8601String()],
            ['lat' => -34.61, 'lng' => -58.38, 'registrado_en' => now()->subHours(23)->toIso8601String()],
        ]])->assertNoContent();

        expect(PuntoRecorrido::where('viaje_id', $viaje->id)->pluck('lat')->all())->toBe([-34.61]);
    });
});

describe('panel', function () {
    it('marca en la línea de tiempo las acciones registradas sin señal', function () {
        $chofer = choferEnTurno();
        $viaje = viajeSinSenal($chofer, ['estado' => EstadoViaje::EnCurso, 'llego_en' => now()->subMinutes(50),
            'iniciado_en' => now()->subMinutes(45)]);

        // Finalizó a las 11:40 (hora local) sin señal; la acción llegó a las 12:00.
        app(ServicioViaje::class)->avanzar($viaje, $chofer, EstadoViaje::Finalizado, now()->subMinutes(20), (string) Str::uuid());
        // El inicio se registró con señal (llegó 1 minuto después): sin marca.
        AccionViaje::create(['viaje_id' => $viaje->id, 'chofer_id' => $chofer->id, 'estado' => EstadoViaje::EnCurso,
            'momento' => now()->subMinutes(45), 'aplicada_en' => now()->subMinutes(44)]);

        $html = $this->actingAs(Usuario::factory()->admin()->create())
            ->get(ViajeResource::getUrl('view', ['record' => $viaje]))
            ->assertOk()
            ->assertSeeInOrder(['01/10/2026 11:15', '01/10/2026 11:40', '(registrado sin señal, enviado 12:00)'])
            ->getContent();

        expect(substr_count($html, 'registrado sin señal'))->toBe(1);
    });
});
