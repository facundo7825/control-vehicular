<?php

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\TipoViaje;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Jobs\AlertarReservaSinTurno;
use App\Jobs\RecordarReserva;
use App\Jobs\VencerOferta;
use App\Models\OfertaViaje;
use App\Models\Parametro;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\CalculadorEstadoChofer;
use App\Servicios\Despachador;
use App\Servicios\DisponibilidadReservas;
use App\Servicios\HorarioLaboral;
use App\Servicios\RotacionViajesLargos;
use App\Servicios\ServicioViaje;
use App\Servicios\ServicioViajesLargos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\Fakes\NotificadorFalso;

beforeEach(function () {
    // Cola sync para el listener de push; los jobs con retraso se falsean y se inspeccionan.
    Bus::fake([VencerOferta::class, RecordarReserva::class, AlertarReservaSinTurno::class]);
    $this->push = new NotificadorFalso;
    $this->app->instance(Notificador::class, $this->push);
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00')); // 09:00 en Buenos Aires
    $this->admin = Usuario::factory()->admin()->create();
});

function largos(): ServicioViajesLargos
{
    return app(ServicioViajesLargos::class);
}

/** Salida el 03/10 06:00 y regreso 20:00 (hora local): de 09:00 a 23:00 UTC, 840 minutos. */
function datosLargo(array $extra = []): array
{
    return [
        'solicitante_id' => Usuario::factory()->create()->id,
        'chofer_id' => Usuario::factory()->chofer()->create()->id,
        'vehiculo_id' => Vehiculo::factory()->create()->id,
        'programado_para' => '2026-10-03 06:00',
        'regreso_estimado' => '2026-10-03 20:00',
        'origen_lat' => -28.469, 'origen_lng' => -65.779, 'origen_direccion' => 'Tribunales, Catamarca',
        'destino_lat' => -28.063, 'destino_lng' => -67.565, 'destino_direccion' => 'Tinogasta',
        'pasajeros' => 'Dra. Pérez y un perito',
        'motivo' => 'Inspección ocular',
        ...$extra,
    ];
}

/** Viaje largo ya asignado (sin pasar por el servicio). */
function largoAsignado(Usuario $chofer, ?Vehiculo $vehiculo, DateTimeInterface $salida, int $minutos, array $attrs = []): Viaje
{
    $salida = Carbon::instance($salida);

    return Viaje::factory()->create([
        'tipo' => TipoViaje::Largo,
        'modo' => ModoViaje::Especifico,
        'chofer_id' => $chofer->id,
        'vehiculo_id' => $vehiculo?->id ?? Vehiculo::factory()->create()->id,
        'estado' => EstadoViaje::Aceptado,
        'aceptado_en' => now(),
        'programado_para' => $salida,
        'regreso_estimado' => $salida->copy()->addMinutes($minutos),
        'duracion_estimada_min' => $minutos,
        ...$attrs,
    ]);
}

describe('crear', function () {
    it('crea el viaje largo aceptado, con chofer, vehículo, regreso y pasajeros, y avisa', function () {
        $datos = datosLargo();

        $viaje = largos()->crear($datos, $this->admin);

        expect($viaje->tipo)->toBe(TipoViaje::Largo)
            ->and($viaje->estado)->toBe(EstadoViaje::Aceptado)
            ->and($viaje->chofer_id)->toBe($datos['chofer_id'])
            ->and($viaje->vehiculo_id)->toBe($datos['vehiculo_id'])
            ->and($viaje->solicitante_id)->toBe($datos['solicitante_id'])
            ->and($viaje->programado_para->eq(Carbon::parse('2026-10-03 09:00:00')))->toBeTrue()
            ->and($viaje->regreso_estimado->eq(Carbon::parse('2026-10-03 23:00:00')))->toBeTrue()
            ->and($viaje->duracion_estimada_min)->toBe(840)
            ->and($viaje->pasajeros)->toBe('Dra. Pérez y un perito')
            ->and($viaje->aceptado_en)->not->toBeNull()
            ->and(OfertaViaje::count())->toBe(0);

        expect($this->push->titulosPara($viaje->chofer))->toBe(['Viaje largo asignado'])
            ->and($this->push->titulosPara($viaje->solicitante))->toBe(['Viaje largo confirmado para 03/10 06:00']);
    });

    it('programa los mismos recordatorios y la alerta de turno que una reserva', function () {
        $viaje = largos()->crear(datosLargo(), $this->admin);
        $salida = $viaje->programado_para;

        Bus::assertDispatched(RecordarReserva::class, 2);
        foreach ([1440, 30] as $minutos) {
            Bus::assertDispatched(RecordarReserva::class, fn ($job) => $job->viajeId === $viaje->id
                && $job->choferId === $viaje->chofer_id
                && $job->delay->eq($salida->copy()->subMinutes($minutos)));
        }
        Bus::assertDispatched(AlertarReservaSinTurno::class, fn ($job) => $job->viajeId === $viaje->id
            && $job->delay->eq($salida->copy()->subMinutes(15)));

        (new RecordarReserva($viaje->id, $viaje->chofer_id, $salida->getTimestamp()))->handle($this->push);
        expect($this->push->titulosPara($viaje->chofer))->toContain('Recordatorio de viaje largo');
    });

    it('rechaza fechas inválidas sin crear nada', function (array $extra, string $mensaje) {
        expect(fn () => largos()->crear(datosLargo($extra), $this->admin))
            ->toThrow(ReglaNegocio::class, $mensaje);

        expect(Viaje::count())->toBe(0);
    })->with([
        'salida pasada' => [['programado_para' => '2026-10-01 08:00'], 'La salida tiene que ser posterior a este momento.'],
        'regreso antes de la salida' => [['regreso_estimado' => '2026-10-03 05:00'], 'El regreso estimado tiene que ser posterior a la salida.'],
        'regreso igual a la salida' => [['regreso_estimado' => '2026-10-03 06:00'], 'El regreso estimado tiene que ser posterior a la salida.'],
        'más de 7 días' => [['regreso_estimado' => '2026-10-10 06:01'], 'Un viaje largo puede durar como mucho 7 días.'],
        'sin regreso' => [['regreso_estimado' => null], 'Indicá la salida y el regreso estimado.'],
    ]);

    it('acepta justo 7 días', function () {
        expect(largos()->crear(datosLargo(['regreso_estimado' => '2026-10-10 06:00']), $this->admin)->duracion_estimada_min)
            ->toBe(7 * 24 * 60);
    });

    it('rechaza chofer o vehículo inexistentes o inactivos sin crear nada', function (Closure $extra, string $mensaje) {
        expect(fn () => largos()->crear(datosLargo($extra()), $this->admin))
            ->toThrow(ReglaNegocio::class, $mensaje);

        expect(Viaje::count())->toBe(0);
    })->with([
        'chofer inactivo' => [fn () => ['chofer_id' => Usuario::factory()->chofer()->create(['activo' => false])->id], 'El chofer elegido no existe o no está activo.'],
        'no es chofer' => [fn () => ['chofer_id' => Usuario::factory()->create()->id], 'El chofer elegido no existe o no está activo.'],
        'vehículo inactivo' => [fn () => ['vehiculo_id' => Vehiculo::factory()->create(['activo' => false])->id], 'El vehículo elegido no existe o no está activo.'],
        'vehículo inexistente' => [fn () => ['vehiculo_id' => 999], 'El vehículo elegido no existe o no está activo.'],
        'solicitante inexistente' => [fn () => ['solicitante_id' => 999], 'El solicitante elegido no existe o no está activo.'],
    ]);

    it('solo lo crea un administrador', function () {
        expect(fn () => largos()->crear(datosLargo(), Usuario::factory()->create()))
            ->toThrow(AccionNoPermitida::class);
    });

    it('rechaza un chofer con una reserva o un viaje largo que se superpone (con colchón)', function () {
        $chofer = Usuario::factory()->chofer()->create();
        // La franja es de 09:00 a 23:00 UTC; con 30 min de colchón choca lo que termine después de 08:30.
        reservaAceptada($chofer, Carbon::parse('2026-10-03 07:31'), 60);

        expect(fn () => largos()->crear(datosLargo(['chofer_id' => $chofer->id]), $this->admin))
            ->toThrow(ReglaNegocio::class, 'El chofer elegido tiene otra reserva o viaje largo en esa franja.');

        $otro = Usuario::factory()->chofer()->create();
        largoAsignado($otro, null, Carbon::parse('2026-10-02 12:00'), 21 * 60 + 1); // termina 03/10 09:01

        expect(fn () => largos()->crear(datosLargo(['chofer_id' => $otro->id]), $this->admin))
            ->toThrow(ReglaNegocio::class, 'El chofer elegido tiene otra reserva o viaje largo en esa franja.');

        expect(Viaje::where('tipo', TipoViaje::Largo)->count())->toBe(1);
    });

    it('acepta un chofer cuya reserva termina justo al empezar el colchón', function () {
        $chofer = Usuario::factory()->chofer()->create();
        reservaAceptada($chofer, Carbon::parse('2026-10-03 07:30'), 60); // termina 08:30

        expect(largos()->crear(datosLargo(['chofer_id' => $chofer->id]), $this->admin)->estado)->toBe(EstadoViaje::Aceptado);
    });

    it('rechaza un vehículo que está en otro viaje largo superpuesto', function () {
        $vehiculo = Vehiculo::factory()->create();
        largoAsignado(Usuario::factory()->chofer()->create(), $vehiculo, Carbon::parse('2026-10-03 20:00'), 600);

        expect(fn () => largos()->crear(datosLargo(['vehiculo_id' => $vehiculo->id]), $this->admin))
            ->toThrow(ReglaNegocio::class, 'El vehículo elegido está en otro viaje largo en esa franja.');

        expect(Viaje::count())->toBe(1);
    });

    it('el vehículo sí puede ir en viajes largos que no se superponen, o en uno finalizado o cancelado', function () {
        $vehiculo = Vehiculo::factory()->create();
        largoAsignado(Usuario::factory()->chofer()->create(), $vehiculo, Carbon::parse('2026-10-04 00:00'), 600);
        largoAsignado(Usuario::factory()->chofer()->create(), $vehiculo, Carbon::parse('2026-10-03 10:00'), 600, ['estado' => EstadoViaje::Cancelado]);
        // Una reserva usa el vehículo del turno: no cuenta.
        reservaAceptada(Usuario::factory()->chofer()->create(), Carbon::parse('2026-10-03 10:00'), 60, ['vehiculo_id' => $vehiculo->id]);

        expect(largos()->crear(datosLargo(['vehiculo_id' => $vehiculo->id]), $this->admin)->estado)->toBe(EstadoViaje::Aceptado);
    });
});

describe('disponibilidad durante el viaje largo', function () {
    it('el chofer no está disponible para reservas en toda la franja del viaje largo', function () {
        $chofer = Usuario::factory()->chofer()->create();
        largoAsignado($chofer, null, Carbon::parse('2026-10-03 09:00'), 3 * 24 * 60); // hasta el 06/10

        $d = app(DisponibilidadReservas::class);
        expect($d->estaDisponible($chofer->id, Carbon::parse('2026-10-05 15:00'), 60))->toBeFalse()
            ->and($d->estaDisponible($chofer->id, Carbon::parse('2026-10-06 09:30'), 60))->toBeTrue()
            ->and($d->choferesDisponibles(Carbon::parse('2026-10-04 12:00'), 60)->pluck('chofer.id'))->not->toContain($chofer->id);
    });

    it('no se le ofrece una reserva que cae dentro del viaje largo', function () {
        $chofer = Usuario::factory()->chofer()->create();
        largoAsignado($chofer, null, Carbon::parse('2026-10-02 09:00'), 2 * 24 * 60);

        $ofrecida = app(Despachador::class)->ofrecerReserva(reservaBuscando(['programado_para' => Carbon::parse('2026-10-03 15:00')]), $chofer);

        expect($ofrecida)->toBeFalse()->and(OfertaViaje::count())->toBe(0);
    });

    it('no recibe ofertas inmediatas con el viaje largo por salir, en curso, ni ya pasada la salida sin arrancar', function (string $estado, int $minutosParaSalir) {
        $chofer = choferEnTurno();
        largoAsignado($chofer, null, now()->addMinutes($minutosParaSalir), 600, ['estado' => EstadoViaje::from($estado)]);

        app(Despachador::class)->despachar(Viaje::factory()->create());

        expect(OfertaViaje::where('chofer_id', $chofer->id)->exists())->toBeFalse()
            ->and(app(CalculadorEstadoChofer::class)->estado($chofer))->not->toBe(EstadoChofer::Libre);
    })->with([
        'sale en 30 minutos' => ['aceptado', 30],
        'ya pasó la salida y no arrancó' => ['aceptado', -120],
        'en camino' => ['en_camino', -60],
        'en curso, a mitad del viaje' => ['en_curso', -300],
    ]);

    it('sí recibe ofertas inmediatas si el viaje largo sale recién mañana', function () {
        $chofer = choferEnTurno();
        largoAsignado($chofer, null, now()->addDay(), 600);

        app(Despachador::class)->despachar(Viaje::factory()->create());

        expect(OfertaViaje::sole()->chofer_id)->toBe($chofer->id)
            ->and(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Pendiente);
    });
});

describe('reasignar', function () {
    it('cambia el chofer y el vehículo, avisa y reprograma los recordatorios', function () {
        $viaje = largos()->crear(datosLargo(), $this->admin);
        $anterior = $viaje->chofer;
        $nuevo = Usuario::factory()->chofer()->create();
        $vehiculo = Vehiculo::factory()->create();
        $this->push->enviados = [];

        largos()->reasignar($viaje, $nuevo, $vehiculo);

        expect($viaje->chofer_id)->toBe($nuevo->id)
            ->and($viaje->vehiculo_id)->toBe($vehiculo->id)
            ->and($viaje->estado)->toBe(EstadoViaje::Aceptado)
            ->and($this->push->titulosPara($anterior))->toBe(['Viaje largo reasignado'])
            ->and($this->push->titulosPara($nuevo))->toBe(['Viaje largo asignado']);
        Bus::assertDispatched(RecordarReserva::class, fn ($job) => $job->choferId === $nuevo->id);
    });

    it('cambia solo el vehículo sin duplicar los recordatorios', function () {
        $viaje = largos()->crear(datosLargo(), $this->admin);
        $vehiculo = Vehiculo::factory()->create();

        largos()->reasignar($viaje, $viaje->chofer, $vehiculo);

        expect($viaje->vehiculo_id)->toBe($vehiculo->id);
        Bus::assertDispatched(RecordarReserva::class, 2);
    });

    it('valida la disponibilidad del chofer y del vehículo, sin contar el propio viaje', function () {
        $viaje = largos()->crear(datosLargo(), $this->admin);
        $ocupado = Usuario::factory()->chofer()->create();
        largoAsignado($ocupado, null, Carbon::parse('2026-10-03 12:00'), 60);
        $vehiculoOcupado = Vehiculo::factory()->create();
        largoAsignado(Usuario::factory()->chofer()->create(), $vehiculoOcupado, Carbon::parse('2026-10-03 12:00'), 60);

        expect(fn () => largos()->reasignar($viaje, $ocupado, $viaje->vehiculo))
            ->toThrow(ReglaNegocio::class, 'El chofer elegido tiene otra reserva o viaje largo en esa franja.')
            ->and(fn () => largos()->reasignar($viaje, $viaje->chofer, $vehiculoOcupado))
            ->toThrow(ReglaNegocio::class, 'El vehículo elegido está en otro viaje largo en esa franja.')
            ->and(fn () => largos()->reasignar($viaje, $viaje->chofer, $viaje->vehiculo))
            ->toThrow(ReglaNegocio::class, 'El viaje ya está asignado a ese chofer con ese vehículo.');
    });

    it('no se reasigna una vez que el chofer salió', function () {
        $viaje = largoAsignado(Usuario::factory()->chofer()->create(), null, now()->subHour(), 600, ['estado' => EstadoViaje::EnCamino]);

        expect(fn () => largos()->reasignar($viaje, Usuario::factory()->chofer()->create(), $viaje->vehiculo))
            ->toThrow(ReglaNegocio::class, 'El viaje largo ya comenzó o terminó; no se puede reasignar.');
    });

    it('"Reasignar" del panel cambia el chofer conservando el vehículo', function () {
        $viaje = largos()->crear(datosLargo(), $this->admin);
        $vehiculoId = $viaje->vehiculo_id;
        $nuevo = Usuario::factory()->chofer()->create();

        app(ServicioViaje::class)->reasignarPorAdmin($viaje, $nuevo);

        expect($viaje->chofer_id)->toBe($nuevo->id)->and($viaje->vehiculo_id)->toBe($vehiculoId);
    });
});

describe('flujo del chofer y del solicitante', function () {
    it('el chofer lo arranca con "Voy en camino" con el vehículo asignado, no antes del bloqueo', function () {
        $chofer = choferEnTurno();
        $vehiculo = Vehiculo::factory()->create();
        $viaje = largoAsignado($chofer, $vehiculo, now()->addHours(2), 600);
        $s = app(ServicioViaje::class);

        expect(fn () => $s->avanzar($viaje, $chofer, EstadoViaje::EnCamino))
            ->toThrow(ReglaNegocio::class, 'Podés salir hacia este viaje largo a partir de las');

        $this->travel(80)->minutes();
        $s->avanzar($viaje, $chofer, EstadoViaje::EnCamino);

        expect($viaje->estado)->toBe(EstadoViaje::EnCamino)->and($viaje->vehiculo_id)->toBe($vehiculo->id);
    });

    it('ni el chofer ni el solicitante pueden cancelarlo', function () {
        $chofer = Usuario::factory()->chofer()->create();
        $viaje = largoAsignado($chofer, null, now()->addDay(), 600);
        $s = app(ServicioViaje::class);

        expect(fn () => $s->cancelarPorChofer($viaje, $chofer, 'No puedo'))->toThrow(AccionNoPermitida::class)
            ->and(fn () => $s->cancelarPorSolicitante($viaje, $viaje->solicitante, null))->toThrow(AccionNoPermitida::class)
            ->and($viaje->refresh()->estado)->toBe(EstadoViaje::Aceptado);
    });

    it('el admin lo cancela y avisa con los textos del viaje largo', function () {
        $viaje = largos()->crear(datosLargo(), $this->admin);
        $this->push->enviados = [];

        app(ServicioViaje::class)->cancelarPorAdmin($viaje, $this->admin, 'Se suspendió la inspección');

        expect($this->push->titulosPara($viaje->chofer))->toBe(['Viaje largo cancelado'])
            ->and($this->push->titulosPara($viaje->solicitante))->toBe(['Viaje largo cancelado']);
    });
});

describe('API', function () {
    it('la agenda del chofer incluye sus viajes largos con regreso y pasajeros', function () {
        $viaje = largos()->crear(datosLargo(), $this->admin);
        $reserva = reservaAceptada($viaje->chofer, Carbon::parse('2026-10-02 15:00'));

        $this->actingAs($viaje->chofer)->getJson('/api/agenda')
            ->assertOk()
            ->assertJsonCount(2, 'reservas')
            ->assertJsonPath('reservas.0.id', $reserva->id)
            ->assertJsonPath('reservas.1.id', $viaje->id)
            ->assertJsonPath('reservas.1.tipo', 'largo')
            ->assertJsonPath('reservas.1.programado_para', '2026-10-03T09:00:00+00:00')
            ->assertJsonPath('reservas.1.regreso_estimado', '2026-10-03T23:00:00+00:00')
            ->assertJsonPath('reservas.1.pasajeros', 'Dra. Pérez y un perito')
            ->assertJsonPath('reservas.1.vehiculo.patente', $viaje->vehiculo->patente);
    });

    it('mis viajes del solicitante lo muestra en las próximas', function () {
        $viaje = largos()->crear(datosLargo(), $this->admin);

        $this->actingAs($viaje->solicitante)->getJson('/api/viajes')
            ->assertOk()
            ->assertJsonCount(1, 'proximas')
            ->assertJsonPath('proximas.0.tipo', 'largo')
            ->assertJsonPath('proximas.0.regreso_estimado', '2026-10-03T23:00:00+00:00')
            ->assertJsonPath('proximas.0.chofer.id', $viaje->chofer_id);
    });

    it('el viaje actual del solicitante incluye el viaje largo una vez que el chofer salió', function () {
        $viaje = largoAsignado(Usuario::factory()->chofer()->create(), null, now()->subMinutes(10), 600, ['estado' => EstadoViaje::EnCamino]);

        $this->actingAs($viaje->solicitante)->getJson('/api/viajes/actual')
            ->assertOk()
            ->assertJsonPath('viaje.id', $viaje->id);
    });

    it('un viaje sin regreso ni pasajeros los devuelve nulos', function () {
        $viaje = Viaje::factory()->create();

        $this->actingAs($viaje->solicitante)->getJson("/api/viajes/{$viaje->id}")
            ->assertOk()
            ->assertJsonPath('regreso_estimado', null)
            ->assertJsonPath('pasajeros', null);
    });
});

describe('rotación', function () {
    it('ordena por el último viaje largo finalizado: los que nunca hicieron uno primero, empates por nombre', function () {
        $reciente = Usuario::factory()->chofer()->create(['nombre' => 'Ana Reciente']);
        $viejo = Usuario::factory()->chofer()->create(['nombre' => 'Bruno Viejo']);
        $nuncaB = Usuario::factory()->chofer()->create(['nombre' => 'Zoe Nunca']);
        $nuncaA = Usuario::factory()->chofer()->create(['nombre' => 'Carla Nunca']);
        $ocupado = Usuario::factory()->chofer()->create(['nombre' => 'Aaron Ocupado']);
        Usuario::factory()->chofer()->create(['nombre' => 'Inactivo', 'activo' => false]);

        largoAsignado($reciente, null, now()->subDays(5), 600, ['estado' => EstadoViaje::Finalizado, 'destino_direccion' => 'Belén']);
        largoAsignado($reciente, null, now()->subDays(100), 600, ['estado' => EstadoViaje::Finalizado, 'destino_direccion' => 'Andalgalá']);
        largoAsignado($viejo, null, now()->subDays(20), 600, ['estado' => EstadoViaje::Finalizado, 'destino_direccion' => 'Tinogasta']);
        // Cancelados no cuentan como último viaje.
        largoAsignado($nuncaA, null, now()->subDays(2), 600, ['estado' => EstadoViaje::Cancelado]);
        largoAsignado($ocupado, null, Carbon::parse('2026-10-03 10:00'), 60);

        $filas = app(RotacionViajesLargos::class)->ordenados(Carbon::parse('2026-10-03 09:00'), Carbon::parse('2026-10-03 23:00'));

        expect($filas->pluck('chofer.id')->all())->toBe([$nuncaA->id, $nuncaB->id, $viejo->id, $reciente->id])
            ->and($filas[0]['ultimo'])->toBeNull()
            ->and($filas[2]['ultimo']['destino'])->toBe('Tinogasta')
            ->and($filas[3]['ultimo']['destino'])->toBe('Belén')
            ->and($filas[3]['recientes'])->toBe(1)
            ->and(RotacionViajesLargos::etiquetaUltimo($filas[2]['ultimo']))->toBe('11/09 (Tinogasta)')
            ->and(RotacionViajesLargos::etiquetaUltimo(null))->toBe('Nunca');
    });

    it('todos() lista a todos los choferes activos con su próximo viaje largo', function () {
        $chofer = Usuario::factory()->chofer()->create();
        $proximo = largoAsignado($chofer, null, Carbon::parse('2026-10-03 10:00'), 60);

        $fila = app(RotacionViajesLargos::class)->todos()->firstWhere('chofer.id', $chofer->id);

        expect($fila['proximo']->id)->toBe($proximo->id);
    });
});

describe('fuera de horario', function () {
    it('marca los viajes que empezaron antes de las 7 o terminaron después de las 18 (hora local)', function (string $inicio, string $fin, bool $esperado) {
        $viaje = Viaje::factory()->make([
            'tipo' => TipoViaje::Largo,
            'iniciado_en' => Carbon::parse($inicio, 'America/Argentina/Buenos_Aires')->utc(),
            'finalizado_en' => Carbon::parse($fin, 'America/Argentina/Buenos_Aires')->utc(),
        ]);

        expect(app(HorarioLaboral::class)->fueraDeHorario($viaje))->toBe($esperado);
    })->with([
        'dentro' => ['2026-10-03 07:00', '2026-10-03 18:00', false],
        'empezó antes' => ['2026-10-03 06:59', '2026-10-03 12:00', true],
        'terminó después' => ['2026-10-03 08:00', '2026-10-03 18:01', true],
        'pasó al otro día' => ['2026-10-03 08:00', '2026-10-04 09:00', true],
    ]);

    it('usa los parámetros del horario laboral y no marca un viaje sin terminar', function () {
        Parametro::create(['clave' => 'horario_laboral_fin', 'valor' => '20']);
        $viaje = Viaje::factory()->make([
            'iniciado_en' => Carbon::parse('2026-10-03 11:00'), // 08:00 local
            'finalizado_en' => Carbon::parse('2026-10-03 22:30'), // 19:30 local
        ]);
        $h = app(HorarioLaboral::class);

        expect($h->fueraDeHorario($viaje))->toBeFalse()
            ->and($h->duracionRealMin($viaje))->toBe(690)
            ->and($h->fueraDeHorario(Viaje::factory()->make(['iniciado_en' => now()->subHours(20)])))->toBeFalse();
    });
});
