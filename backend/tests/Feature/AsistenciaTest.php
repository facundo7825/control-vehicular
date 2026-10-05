<?php

use App\Enums\EstadoViaje;
use App\Enums\OrigenTurno;
use App\Models\Alerta;
use App\Models\EventoAsistencia;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\MaquinaEstadosViaje;
use Illuminate\Support\Carbon;
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
