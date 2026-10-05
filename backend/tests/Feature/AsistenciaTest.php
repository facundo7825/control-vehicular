<?php

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\OrigenTurno;
use App\Models\Alerta;
use App\Models\EventoAsistencia;
use App\Models\OfertaViaje;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\CalculadorEstadoChofer;
use App\Servicios\Despachador;
use App\Servicios\DisponibilidadReservas;
use App\Servicios\MaquinaEstadosViaje;
use App\Servicios\ServicioAsistencia;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\NotificadorFalso;

beforeEach(function () {
    config(['vehiculos.asistencia.clave' => 'clave-secreta']);
    $this->push = new NotificadorFalso;
    $this->app->instance(Notificador::class, $this->push);
    $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));

    $this->fichar = fn (array $cuerpo) => $this->withHeader('X-Clave-Asistencia', 'clave-secreta')
        ->postJson('/api/asistencia/eventos', $cuerpo);
});

function choferConHabitual(array $attrs = []): Usuario
{
    return Usuario::factory()->chofer()->create([
        'nombre' => 'Carlos Chofer',
        'id_externo' => 'L123',
        'vehiculo_habitual_id' => Vehiculo::factory()->create()->id,
        ...$attrs,
    ]);
}

describe('autenticación', function () {
    it('responde 503 si la integración no tiene clave configurada', function () {
        config(['vehiculos.asistencia.clave' => null]);

        $this->withHeader('X-Clave-Asistencia', 'cualquiera')
            ->postJson('/api/asistencia/eventos', ['id_externo' => 'L123', 'tipo' => 'entrada'])
            ->assertStatus(503);
    });

    it('responde 401 sin clave o con una clave incorrecta', function () {
        $this->postJson('/api/asistencia/eventos', ['id_externo' => 'L123', 'tipo' => 'entrada'])->assertUnauthorized();
        $this->withHeader('X-Clave-Asistencia', 'otra')
            ->postJson('/api/asistencia/eventos', ['id_externo' => 'L123', 'tipo' => 'entrada'])
            ->assertUnauthorized();

        expect(EventoAsistencia::count())->toBe(0);
    });

    it('acepta la clave correcta', function () {
        ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada'])->assertOk();
    });
});

it('valida el cuerpo', function () {
    ($this->fichar)([])->assertStatus(422)->assertJsonValidationErrors(['id_externo', 'tipo']);
    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'almuerzo'])->assertStatus(422)->assertJsonValidationErrors('tipo');
    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => 'ayer a la tarde'])
        ->assertStatus(422)->assertJsonValidationErrors('momento');
    ($this->fichar)(['eventos' => []])->assertStatus(422)->assertJsonValidationErrors('eventos');
    ($this->fichar)(['eventos' => array_fill(0, 101, ['id_externo' => 'L123', 'tipo' => 'entrada'])])
        ->assertStatus(422)->assertJsonValidationErrors('eventos');
    ($this->fichar)(['eventos' => [['id_externo' => 'L123']]])
        ->assertStatus(422)->assertJsonValidationErrors('eventos.0.tipo');

    expect(EventoAsistencia::count())->toBe(0);
});

it('la entrada abre el turno con el vehículo habitual, origen asistencia, y avisa al chofer', function () {
    $chofer = choferConHabitual();

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => '2026-10-05T08:55:00'])
        ->assertOk()
        ->assertJsonPath('resultado', 'abierto');

    $turno = $chofer->turnoAbierto()->first();
    expect($turno->vehiculo_id)->toBe($chofer->vehiculo_habitual_id)
        ->and($turno->origen)->toBe(OrigenTurno::Asistencia)
        ->and($this->push->enviados)->toHaveCount(1)
        ->and($this->push->enviados[0])->toMatchArray([
            'destino' => $chofer->id,
            'titulo' => 'Tu turno empezó',
            'cuerpo' => 'Abrí la app para compartir tu ubicación',
            'datos' => ['tipo' => 'turno', 'estado' => 'abierto'],
        ]);

    // Sin zona es hora local (UTC-3): queda guardado en UTC.
    $evento = EventoAsistencia::sole();
    expect($evento->usuario_id)->toBe($chofer->id)
        ->and($evento->resultado)->toBe('abierto')
        ->and($evento->momento->toDateTimeString())->toBe('2026-10-05 11:55:00');
});

it('sin vehículo habitual disponible responde sin_vehiculo, crea la alerta y avisa al chofer', function (string $caso) {
    $chofer = choferConHabitual();
    match ($caso) {
        'sin habitual' => $chofer->update(['vehiculo_habitual_id' => null]),
        'inactivo' => $chofer->vehiculoHabitual->update(['activo' => false]),
        'en uso' => Turno::factory()->create(['vehiculo_id' => $chofer->vehiculo_habitual_id]),
    };

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada'])
        ->assertOk()
        ->assertJsonPath('resultado', 'sin_vehiculo');

    $alerta = Alerta::sole();
    expect($chofer->turnoAbierto()->exists())->toBeFalse()
        ->and($alerta->tipo)->toBe(Alerta::ASISTENCIA_SIN_VEHICULO)
        ->and($alerta->chofer_id)->toBe($chofer->id)
        ->and($alerta->mensaje)->toBe('Carlos Chofer fichó la entrada pero no tiene vehículo habitual disponible')
        ->and($this->push->enviados)->toHaveCount(1)
        ->and($this->push->enviados[0])->toMatchArray([
            'destino' => $chofer->id,
            'titulo' => 'Fichaste la entrada',
            'cuerpo' => 'Abrí la app y elegí el vehículo para empezar el turno',
        ]);
})->with(['sin habitual', 'inactivo', 'en uso']);

it('ignora la entrada si ya tiene un turno abierto', function () {
    $chofer = choferConHabitual();

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => '2026-10-05T08:00:00'])
        ->assertJsonPath('resultado', 'abierto');
    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => '2026-10-05T08:05:00'])
        ->assertOk()
        ->assertJsonPath('resultado', 'ignorado')
        ->assertJsonPath('motivo', 'Ya tenía un turno abierto.');

    expect(Turno::where('chofer_id', $chofer->id)->count())->toBe(1)
        ->and($this->push->enviados)->toHaveCount(1);
});

it('la salida cierra el turno (también uno manual), borra la ubicación y avisa al chofer', function () {
    $chofer = choferConHabitual();
    $turno = Turno::factory()->create(['chofer_id' => $chofer->id, 'origen' => OrigenTurno::Manual]);
    UbicacionChofer::create(['chofer_id' => $chofer->id, 'lat' => -34.6, 'lng' => -58.4, 'actualizado_en' => now()]);

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'salida'])
        ->assertOk()
        ->assertJsonPath('resultado', 'cerrado');

    expect($turno->fresh()->fin)->not->toBeNull()
        ->and(UbicacionChofer::find($chofer->id))->toBeNull()
        ->and($this->push->enviados)->toHaveCount(1)
        ->and($this->push->enviados[0])->toMatchArray([
            'destino' => $chofer->id,
            'titulo' => 'Tu turno terminó',
            'datos' => ['tipo' => 'turno', 'estado' => 'cerrado'],
        ]);
});

it('ignora la salida sin turno abierto', function () {
    choferConHabitual();

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'salida'])
        ->assertOk()
        ->assertJsonPath('resultado', 'ignorado')
        ->assertJsonPath('motivo', 'No tenía un turno abierto.');

    expect($this->push->enviados)->toBe([]);
});

it('la salida con un viaje activo deja el cierre pendiente y al finalizar el viaje se cierra el turno', function () {
    $chofer = choferConHabitual();
    $turno = Turno::factory()->create(['chofer_id' => $chofer->id]);
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'vehiculo_id' => $turno->vehiculo_id, 'estado' => EstadoViaje::EnCurso]);

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'salida'])
        ->assertOk()
        ->assertJsonPath('resultado', 'cierre_pendiente');

    expect($turno->fresh()->fin)->toBeNull()
        ->and($turno->fresh()->cierre_pendiente_en)->not->toBeNull()
        ->and($this->push->titulosPara($chofer))->not->toContain('Tu turno terminó');

    $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => 'finalizado'])->assertOk();

    expect($turno->fresh()->fin)->not->toBeNull()
        ->and($this->push->titulosPara($chofer))->toContain('Tu turno terminó');
});

it('al cancelar el viaje también se cierra el turno con cierre pendiente', function () {
    $chofer = choferConHabitual();
    $turno = Turno::factory()->create(['chofer_id' => $chofer->id]);
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'vehiculo_id' => $turno->vehiculo_id, 'estado' => EstadoViaje::EnCamino]);

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'salida'])->assertJsonPath('resultado', 'cierre_pendiente');

    app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::Cancelado, ['cancelado_por' => 'solicitante']);

    expect($turno->fresh()->fin)->not->toBeNull();
});

it('terminar un viaje no cierra un turno sin cierre pendiente', function () {
    $chofer = choferConHabitual();
    $turno = Turno::factory()->create(['chofer_id' => $chofer->id]);
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'vehiculo_id' => $turno->vehiculo_id, 'estado' => EstadoViaje::EnCurso]);

    $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => 'finalizado'])->assertOk();

    expect($turno->fresh()->fin)->toBeNull();
});

it('una entrada posterior anula el cierre pendiente', function () {
    $chofer = choferConHabitual();
    $turno = Turno::factory()->create(['chofer_id' => $chofer->id]);
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'vehiculo_id' => $turno->vehiculo_id, 'estado' => EstadoViaje::EnCurso]);

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'salida', 'momento' => '2026-10-05T08:00:00'])
        ->assertJsonPath('resultado', 'cierre_pendiente');
    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => '2026-10-05T08:10:00'])
        ->assertJsonPath('resultado', 'ignorado')
        ->assertJsonPath('motivo', 'Ya tenía un turno abierto; se anuló el cierre pendiente.');

    $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => 'finalizado'])->assertOk();

    expect($turno->fresh()->fin)->toBeNull()
        ->and($turno->fresh()->cierre_pendiente_en)->toBeNull();
});

it('un id_evento repetido devuelve el mismo resultado sin volver a procesarlo', function () {
    $chofer = choferConHabitual();

    ($this->fichar)(['id_evento' => 'E-1', 'id_externo' => 'L123', 'tipo' => 'entrada'])
        ->assertJsonPath('resultado', 'abierto');
    $chofer->turnoAbierto()->first()->update(['fin' => now()]);

    ($this->fichar)(['id_evento' => 'E-1', 'id_externo' => 'L123', 'tipo' => 'entrada'])
        ->assertOk()
        ->assertJsonPath('resultado', 'abierto')
        ->assertJsonPath('id_evento', 'E-1');

    expect($chofer->turnoAbierto()->exists())->toBeFalse()
        ->and(EventoAsistencia::count())->toBe(1)
        ->and($this->push->enviados)->toHaveCount(1);
});

it('ignora un evento anterior al último procesado de esa persona', function () {
    $chofer = choferConHabitual();
    Turno::factory()->create(['chofer_id' => $chofer->id]);

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => '2026-10-05T09:00:00'])
        ->assertJsonPath('resultado', 'ignorado');
    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'salida', 'momento' => '2026-10-05T08:00:00-03:00'])
        ->assertOk()
        ->assertJsonPath('resultado', 'ignorado')
        ->assertJsonPath('motivo', 'Evento fuera de orden: es anterior al último fichaje procesado.');

    expect($chofer->turnoAbierto()->exists())->toBeTrue();
});

it('procesa un lote en orden cronológico y responde en el orden recibido', function () {
    $chofer = choferConHabitual();
    Usuario::factory()->chofer()->create(['id_externo' => 'L999']);

    ($this->fichar)(['eventos' => [
        ['id_evento' => 'E-2', 'id_externo' => 'L123', 'tipo' => 'salida', 'momento' => '2026-10-05T08:30:00'],
        ['id_evento' => 'E-1', 'id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => '2026-10-05T08:00:00'],
        ['id_evento' => 'E-3', 'id_externo' => 'L999', 'tipo' => 'entrada', 'momento' => '2026-10-05T08:00:00'],
    ]])
        ->assertOk()
        ->assertJsonCount(3, 'resultados')
        ->assertJsonPath('resultados.0.id_evento', 'E-2')
        ->assertJsonPath('resultados.0.resultado', 'cerrado')
        ->assertJsonPath('resultados.1.id_evento', 'E-1')
        ->assertJsonPath('resultados.1.resultado', 'abierto')
        ->assertJsonPath('resultados.2.resultado', 'sin_vehiculo');

    expect(Turno::where('chofer_id', $chofer->id)->whereNotNull('fin')->count())->toBe(1);
});

it('ignora a un usuario desconocido, deshabilitado o que no es chofer', function (string $caso, string $motivo) {
    match ($caso) {
        'desconocido' => null,
        'inactivo' => choferConHabitual(['activo' => false]),
        'solicitante' => Usuario::factory()->create(['id_externo' => 'L123']),
    };

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada'])
        ->assertOk()
        ->assertJsonPath('resultado', 'ignorado')
        ->assertJsonPath('motivo', $motivo);

    expect(Turno::count())->toBe(0)
        ->and(EventoAsistencia::sole()->resultado)->toBe('ignorado')
        ->and($this->push->enviados)->toBe([]);
})->with([
    ['desconocido', 'No hay ningún usuario con ese id_externo.'],
    ['inactivo', 'El usuario está deshabilitado.'],
    ['solicitante', 'El usuario no es chofer.'],
]);

describe('ventana del momento', function () {
    it('rechaza un momento más de 5 minutos en el futuro', function () {
        choferConHabitual();

        ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => '2026-10-05T12:06:00Z'])
            ->assertStatus(422)
            ->assertJsonPath('errors.momento.0', 'El momento no puede estar más de 5 minutos en el futuro.');
        ($this->fichar)(['eventos' => [['id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => '2099-01-01T00:00:00']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('eventos.0.momento');

        expect(EventoAsistencia::count())->toBe(0)->and(Turno::count())->toBe(0);
    });

    it('rechaza un momento de más de 7 días atrás (también fuera del rango de la base)', function (string $momento) {
        choferConHabitual();

        ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => $momento])
            ->assertStatus(422)
            ->assertJsonPath('errors.momento.0', 'El momento no puede tener más de 7 días de antigüedad.');

        expect(EventoAsistencia::count())->toBe(0)->and(Turno::count())->toBe(0);
    })->with(['2026-09-28T11:59:00Z', '1900-01-01T00:00:00']);

    it('acepta los bordes de la ventana', function () {
        choferConHabitual();

        ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => '2026-09-28T12:00:00Z'])
            ->assertOk()->assertJsonPath('resultado', 'abierto');
        ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'salida', 'momento' => '2026-10-05T12:05:00Z'])
            ->assertOk()->assertJsonPath('resultado', 'cerrado');
    });
});

it('si no se puede registrar el evento no queda ni el turno ni el push', function () {
    $chofer = choferConHabitual();
    EventoAsistencia::creating(fn () => throw new RuntimeException('base caída'));

    ($this->fichar)(['id_evento' => 'E-1', 'id_externo' => 'L123', 'tipo' => 'entrada'])->assertStatus(500);

    expect($chofer->turnoAbierto()->exists())->toBeFalse()
        ->and(Alerta::count())->toBe(0)
        ->and($this->push->enviados)->toBe([]);
});

it('en un lote, un evento que falla queda ignorado y los demás se procesan', function () {
    choferConHabitual();
    Usuario::factory()->chofer()->create(['id_externo' => 'L999']);
    EventoAsistencia::creating(function (EventoAsistencia $e) {
        if ($e->id_externo === 'L999') {
            throw new RuntimeException('falla');
        }
    });

    ($this->fichar)(['eventos' => [
        ['id_externo' => 'L999', 'tipo' => 'entrada'],
        ['id_externo' => 'L123', 'tipo' => 'entrada'],
    ]])
        ->assertOk()
        ->assertJsonPath('resultados.0.resultado', 'ignorado')
        ->assertJsonPath('resultados.0.motivo', 'error interno al procesar el evento')
        ->assertJsonPath('resultados.1.resultado', 'abierto');

    expect(Turno::count())->toBe(1)->and(Alerta::count())->toBe(0);
});

it('limita los eventos a 120 por minuto por IP', function () {
    for ($i = 0; $i < 120; $i++) {
        ($this->fichar)(['id_externo' => 'X'.$i, 'tipo' => 'entrada'])->assertOk();
    }

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada'])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Demasiados eventos de asistencia. Probá de nuevo en un minuto.');
});

it('el push de sin_vehiculo y el de cierre llevan los datos y textos acordados', function () {
    $chofer = choferConHabitual(['vehiculo_habitual_id' => null]);

    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada', 'momento' => '2026-10-05T08:00:00']);
    Turno::factory()->create(['chofer_id' => $chofer->id]);
    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'salida', 'momento' => '2026-10-05T08:30:00']);

    expect($this->push->enviados[0]['datos'])->toBe(['tipo' => 'turno', 'estado' => 'sin_vehiculo'])
        ->and($this->push->enviados[1])->toMatchArray([
            'titulo' => 'Tu turno terminó',
            'cuerpo' => 'Se registró tu salida.',
            'datos' => ['tipo' => 'turno', 'estado' => 'cerrado'],
        ]);
});

it('al iniciar un turno se resuelven sus alertas de fichaje sin vehículo', function () {
    $chofer = choferConHabitual(['vehiculo_habitual_id' => null]);
    $otro = Usuario::factory()->chofer()->create();
    ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'entrada'])->assertJsonPath('resultado', 'sin_vehiculo');
    $ajena = Alerta::create(['tipo' => Alerta::ASISTENCIA_SIN_VEHICULO, 'chofer_id' => $otro->id, 'mensaje' => 'x']);

    $this->actingAs($chofer)->postJson('/api/turnos', ['vehiculo_id' => Vehiculo::factory()->create()->id])->assertCreated();

    expect(Alerta::where('chofer_id', $chofer->id)->sole()->resuelta_en)->not->toBeNull()
        ->and($ajena->fresh()->resuelta_en)->toBeNull();
});

describe('cierre pendiente', function () {
    it('un chofer con cierre pendiente y sin viaje no recibe viajes ni reservas nuevas', function () {
        Queue::fake(); // el vencimiento de la oferta
        $pendiente = choferEnTurno(-34.601, -58.381);
        $pendiente->turnoAbierto->update(['cierre_pendiente_en' => now()]);
        $otro = choferEnTurno(-34.650, -58.450);
        $estados = app(CalculadorEstadoChofer::class);

        expect($estados->estado($pendiente))->toBe(EstadoChofer::FueraDeTurno)
            ->and($estados->libres()->pluck('id')->all())->toBe([$otro->id]);

        $viaje = Viaje::factory()->create(['origen_lat' => -34.600, 'origen_lng' => -58.380]);
        app(Despachador::class)->despachar($viaje);
        expect(OfertaViaje::sole()->chofer_id)->toBe($otro->id);

        $disponibilidad = app(DisponibilidadReservas::class);
        $manana = now()->addDay();
        expect($disponibilidad->estaDisponible($pendiente->id, $manana, 60))->toBeFalse()
            ->and($disponibilidad->choferesDisponibles($manana, 60)->pluck('chofer.id')->all())->toBe([$otro->id]);
    });

    it('con un viaje activo sigue en viaje', function () {
        $chofer = choferEnTurno();
        $chofer->turnoAbierto->update(['cierre_pendiente_en' => now()]);
        Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::EnCurso]);

        expect(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::EnViaje);
    });

    it('si se le reasigna el viaje a otro chofer, se cierra el turno del anterior', function () {
        $anterior = choferEnTurno();
        $nuevo = choferEnTurno();
        $anterior->update(['id_externo' => 'L123']);
        $turno = $anterior->turnoAbierto;
        $viaje = Viaje::factory()->create([
            'chofer_id' => $anterior->id, 'vehiculo_id' => $turno->vehiculo_id,
            'estado' => EstadoViaje::EnCamino, 'aceptado_en' => now()->subMinutes(5),
        ]);

        ($this->fichar)(['id_externo' => 'L123', 'tipo' => 'salida'])->assertJsonPath('resultado', 'cierre_pendiente');

        app(ServicioViaje::class)->reasignarPorAdmin($viaje, $nuevo);

        expect($turno->fresh()->fin)->not->toBeNull()
            ->and($this->push->titulosPara($anterior))->toContain('Tu turno terminó');
    });

    it('si el cierre falla, terminar el viaje no da error y se reporta', function () {
        Exceptions::fake();
        $chofer = choferConHabitual();
        $turno = Turno::factory()->create(['chofer_id' => $chofer->id, 'cierre_pendiente_en' => now()]);
        $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'vehiculo_id' => $turno->vehiculo_id, 'estado' => EstadoViaje::EnCurso]);
        $this->mock(ServicioAsistencia::class)
            ->shouldReceive('cerrarPendiente')->andThrow(new RuntimeException('falla al cerrar'));

        $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => 'finalizado'])->assertOk();

        expect($viaje->fresh()->estado)->toBe(EstadoViaje::Finalizado)
            ->and($turno->fresh()->fin)->toBeNull();
        Exceptions::assertReported(RuntimeException::class);
    });
});
