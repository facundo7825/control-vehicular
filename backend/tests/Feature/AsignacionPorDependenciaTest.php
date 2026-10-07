<?php

use App\Enums\CriterioOferta;
use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\RolUsuario;
use App\Mapas\ServicioMapas;
use App\Models\CargoPrioritario;
use App\Models\Dependencia;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\Asignador;
use App\Servicios\Despachador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

/** Solicitante con dependencia (atendida por $choferesDependencia) y, opcionalmente, chofer asignado. */
function solicitanteCon(?Usuario $asignado = null, array $choferesDependencia = [], bool $dependenciaActiva = true): Usuario
{
    $dependencia = Dependencia::create(['nombre' => 'Fuero '.fake()->unique()->word(), 'activa' => $dependenciaActiva]);
    $dependencia->choferes()->attach(collect($choferesDependencia)->pluck('id'));

    return Usuario::factory()->create([
        'dependencia_id' => $dependencia->id,
        'chofer_asignado_id' => $asignado?->id,
    ]);
}

function viajeDe(Usuario $solicitante, array $attrs = []): Viaje
{
    return Viaje::factory()->create([
        'solicitante_id' => $solicitante->id, 'origen_lat' => -34.600, 'origen_lng' => -58.380, ...$attrs,
    ]);
}

/** @return array<int, array{0: int, 1: CriterioOferta}> */
function ordenDe(Viaje $viaje, array $choferes): array
{
    return app(Asignador::class)
        ->ordenar($viaje, collect($choferes)->each->load('ubicacion'))
        ->map(fn (array $f) => [$f['chofer']->id, $f['criterio']])
        ->all();
}

describe('orden de ofrecimiento', function () {
    it('pone primero al chofer asignado aunque esté más lejos, después la dependencia y después el resto', function () {
        $cerca = choferEnTurno(-34.601, -58.381);
        $depLejos = choferEnTurno(-34.650, -58.430);
        $depCerca = choferEnTurno(-34.610, -58.390);
        $asignado = choferEnTurno(-34.700, -58.480);
        $viaje = viajeDe(solicitanteCon($asignado, [$depLejos, $depCerca]));

        expect(ordenDe($viaje, [$cerca, $depLejos, $depCerca, $asignado]))->toBe([
            [$asignado->id, CriterioOferta::ChoferAsignado],
            [$depCerca->id, CriterioOferta::Dependencia],
            [$depLejos->id, CriterioOferta::Dependencia],
            [$cerca->id, CriterioOferta::Cercania],
        ]);
    });

    it('consulta el servicio de mapas una sola vez para todos los grupos', function () {
        $espia = new class implements ServicioMapas
        {
            public int $llamadas = 0;

            public function duracionesHacia(array $origenes, float $lat, float $lng): array
            {
                $this->llamadas++;

                return array_map(fn () => null, $origenes);
            }

            public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
            {
                return null;
            }
        };
        $this->app->instance(ServicioMapas::class, $espia);
        $dep = choferEnTurno(-34.610, -58.390);
        $asignado = choferEnTurno(-34.700, -58.480);
        $viaje = viajeDe(solicitanteCon($asignado, [$dep]));

        ordenDe($viaje, [choferEnTurno(-34.601, -58.381), $dep, $asignado]);

        expect($espia->llamadas)->toBe(1);
    });

    it('ignora al chofer asignado si está inactivo o dejó de ser chofer', function (array $cambio) {
        $cerca = choferEnTurno(-34.601, -58.381);
        $asignado = choferEnTurno(-34.700, -58.480);
        $viaje = viajeDe(solicitanteCon($asignado));
        $asignado->update($cambio);

        expect(ordenDe($viaje, [$asignado, $cerca]))->toBe([
            [$cerca->id, CriterioOferta::Cercania],
            [$asignado->id, CriterioOferta::Cercania],
        ]);
    })->with([
        'inactivo' => [['activo' => false]],
        'ya no es chofer' => [['rol' => RolUsuario::Solicitante]],
    ]);

    it('ignora la dependencia si está inactiva', function () {
        $cerca = choferEnTurno(-34.601, -58.381);
        $dep = choferEnTurno(-34.700, -58.480);
        $viaje = viajeDe(solicitanteCon(null, [$dep], dependenciaActiva: false));

        expect(ordenDe($viaje, [$dep, $cerca]))->toBe([
            [$cerca->id, CriterioOferta::Cercania],
            [$dep->id, CriterioOferta::Cercania],
        ]);
    });

    it('no cuenta como de la dependencia a un chofer inactivo', function () {
        $cerca = choferEnTurno(-34.601, -58.381);
        $dep = choferEnTurno(-34.700, -58.480);
        $viaje = viajeDe(solicitanteCon(null, [$dep]));
        $dep->update(['activo' => false]);

        expect(ordenDe($viaje, [$dep, $cerca]))->toBe([
            [$cerca->id, CriterioOferta::Cercania],
            [$dep->id, CriterioOferta::Cercania],
        ]);
    });

    it('sin dependencia ni chofer asignado ordena solo por cercanía', function () {
        $lejos = choferEnTurno(-34.700, -58.480);
        $cerca = choferEnTurno(-34.601, -58.381);
        $viaje = viajeDe(Usuario::factory()->create());

        expect(ordenDe($viaje, [$lejos, $cerca]))->toBe([
            [$cerca->id, CriterioOferta::Cercania],
            [$lejos->id, CriterioOferta::Cercania],
        ]);
    });
});

describe('despacho', function () {
    it('ofrece primero al chofer asignado y registra el criterio; si rechaza, sigue la dependencia y después el resto', function () {
        $cerca = choferEnTurno(-34.601, -58.381);
        $dep = choferEnTurno(-34.650, -58.430);
        $asignado = choferEnTurno(-34.700, -58.480);
        $viaje = viajeDe(solicitanteCon($asignado, [$dep]));
        $d = app(Despachador::class);

        $d->despachar($viaje);
        $primera = OfertaViaje::sole();
        expect($primera->chofer_id)->toBe($asignado->id)
            ->and($primera->criterio)->toBe(CriterioOferta::ChoferAsignado);

        $d->responder($primera, false);
        $segunda = OfertaViaje::where('resultado', ResultadoOferta::Pendiente)->sole();
        expect($segunda->chofer_id)->toBe($dep->id)
            ->and($segunda->criterio)->toBe(CriterioOferta::Dependencia);

        $d->responder($segunda, false);
        $tercera = OfertaViaje::where('resultado', ResultadoOferta::Pendiente)->sole();
        expect($tercera->chofer_id)->toBe($cerca->id)
            ->and($tercera->criterio)->toBe(CriterioOferta::Cercania);
    });

    it('si el chofer asignado no está libre ofrece a la dependencia', function () {
        $cerca = choferEnTurno(-34.601, -58.381);
        $dep = choferEnTurno(-34.650, -58.430);
        $asignado = choferEnTurno(-34.700, -58.480);
        Viaje::factory()->create(['chofer_id' => $asignado->id, 'estado' => EstadoViaje::EnCurso]);
        $viaje = viajeDe(solicitanteCon($asignado, [$dep]));

        app(Despachador::class)->despachar($viaje);

        expect(OfertaViaje::sole())->chofer_id->toBe($dep->id)->criterio->toBe(CriterioOferta::Dependencia);
    });

    it('con el chofer asignado inactivo ofrece a la dependencia y al resto, y nunca al inactivo', function () {
        $asignado = choferEnTurno(-34.601, -58.381);
        $dep = choferEnTurno(-34.650, -58.430);
        $resto = choferEnTurno(-34.700, -58.480);
        $viaje = viajeDe(solicitanteCon($asignado, [$dep]));
        $asignado->update(['activo' => false]);
        $d = app(Despachador::class);

        $d->despachar($viaje);
        expect(OfertaViaje::sole())->chofer_id->toBe($dep->id)->criterio->toBe(CriterioOferta::Dependencia);

        $d->responder(OfertaViaje::sole(), false);
        expect(OfertaViaje::where('resultado', ResultadoOferta::Pendiente)->sole()->chofer_id)->toBe($resto->id);

        $d->responder(OfertaViaje::where('resultado', ResultadoOferta::Pendiente)->sole(), false);
        expect(OfertaViaje::where('chofer_id', $asignado->id)->exists())->toBeFalse()
            ->and($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer);
    });

    it('un viaje obligatorio no se asigna a un chofer inactivo', function () {
        $inactivo = choferEnTurno(-34.601, -58.381);
        $inactivo->update(['activo' => false]);
        $activo = choferEnTurno(-34.700, -58.480);
        $viaje = viajeDe(Usuario::factory()->create(), ['obligatorio' => true]);

        app(Despachador::class)->despachar($viaje);

        expect($viaje->fresh()->chofer_id)->toBe($activo->id);
    });

    it('un viaje obligatorio se asigna directo al chofer asignado', function () {
        choferEnTurno(-34.601, -58.381);
        $asignado = choferEnTurno(-34.700, -58.480);
        $viaje = viajeDe(solicitanteCon($asignado), ['obligatorio' => true]);

        app(Despachador::class)->despachar($viaje);

        expect($viaje->fresh())->estado->toBe(EstadoViaje::Aceptado)->chofer_id->toBe($asignado->id)
            ->and(OfertaViaje::count())->toBe(0);
    });

    it('pedir a un chofer específico no cambia y registra que lo eligió el solicitante', function () {
        $otro = choferEnTurno(-34.601, -58.381);
        $asignado = choferEnTurno(-34.700, -58.480);
        $viaje = viajeDe(solicitanteCon($asignado));

        app(Despachador::class)->pedirA($viaje, $otro);

        expect(OfertaViaje::sole())->chofer_id->toBe($otro->id)->criterio->toBe(CriterioOferta::ElegidoPorSolicitante);
    });
});

describe('reservas', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
        fijarDuracionRuta(1500);
    });

    function pedirReserva(Usuario $solicitante, array $extra = []): void
    {
        test()->actingAs($solicitante)->postJson('/api/reservas', [
            'programado_para' => '2026-10-02T12:00:00-03:00',
            'modo' => 'cualquiera_disponible',
            'origen_lat' => -34.600, 'origen_lng' => -58.380, 'origen_direccion' => 'Talcahuano 550',
            'destino_lat' => -34.609, 'destino_lng' => -58.392, 'destino_direccion' => 'Tribunales',
            ...$extra,
        ])->assertCreated();
    }

    it('cualquiera disponible ofrece primero al chofer asignado aunque tenga más reservas ese día', function () {
        Usuario::factory()->chofer()->create();
        $asignado = Usuario::factory()->chofer()->create();
        reservaAceptada($asignado, Carbon::parse('2026-10-02 19:00'));

        pedirReserva(solicitanteCon($asignado));

        expect(OfertaViaje::sole())->chofer_id->toBe($asignado->id)->criterio->toBe(CriterioOferta::ChoferAsignado);
    });

    it('cualquiera disponible sigue con la dependencia, en el orden de siempre dentro del grupo', function () {
        Usuario::factory()->chofer()->create();
        $depCargado = Usuario::factory()->chofer()->create();
        reservaAceptada($depCargado, Carbon::parse('2026-10-02 19:00'));
        $depLiviano = Usuario::factory()->chofer()->create();

        pedirReserva(solicitanteCon(null, [$depCargado, $depLiviano]));

        expect(OfertaViaje::sole())->chofer_id->toBe($depLiviano->id)->criterio->toBe(CriterioOferta::Dependencia);
    });

    it('al resto lo sigue eligiendo por menos reservas en el día, con criterio disponibilidad', function () {
        $cargado = Usuario::factory()->chofer()->create();
        reservaAceptada($cargado, Carbon::parse('2026-10-02 19:00'));
        $liviano = Usuario::factory()->chofer()->create();

        pedirReserva(solicitanteCon());

        expect(OfertaViaje::sole())->chofer_id->toBe($liviano->id)->criterio->toBe(CriterioOferta::Disponibilidad);
    });

    it('si el chofer asignado está ocupado en esa franja pasa a la dependencia', function () {
        $asignado = Usuario::factory()->chofer()->create();
        reservaAceptada($asignado, Carbon::parse('2026-10-02 15:30'));
        $dep = Usuario::factory()->chofer()->create();

        pedirReserva(solicitanteCon($asignado, [$dep]));

        expect(OfertaViaje::sole())->chofer_id->toBe($dep->id)->criterio->toBe(CriterioOferta::Dependencia);
    });

    it('una reserva obligatoria se asigna directo al chofer asignado', function () {
        CargoPrioritario::create(['cargo' => 'Juez', 'obligatorio' => true]);
        Usuario::factory()->chofer()->create();
        $asignado = Usuario::factory()->chofer()->create();
        reservaAceptada($asignado, Carbon::parse('2026-10-02 19:00'));
        $juez = solicitanteCon($asignado);
        $juez->update(['cargo' => 'Juez']);

        pedirReserva($juez);

        expect(Viaje::where('solicitante_id', $juez->id)->sole())
            ->estado->toBe(EstadoViaje::Aceptado)->chofer_id->toBe($asignado->id)
            ->and(OfertaViaje::count())->toBe(0);
    });

    it('cualquiera disponible nunca ofrece a un chofer inactivo, aunque sea el asignado', function () {
        $asignado = Usuario::factory()->chofer()->create(['activo' => false]);
        $otro = Usuario::factory()->chofer()->create();

        pedirReserva(solicitanteCon($asignado));

        expect(OfertaViaje::sole())->chofer_id->toBe($otro->id)->criterio->toBe(CriterioOferta::Disponibilidad);
    });

    it('el modo específico no cambia', function () {
        $asignado = Usuario::factory()->chofer()->create();
        $elegido = Usuario::factory()->chofer()->create();

        pedirReserva(solicitanteCon($asignado), ['modo' => 'especifico', 'chofer_id' => $elegido->id]);

        expect(OfertaViaje::sole())->chofer_id->toBe($elegido->id)->criterio->toBe(CriterioOferta::ElegidoPorSolicitante);
    });
});

describe('chofer desactivado después de elegirlo', function () {
    // El chofer en memoria sigue activo (como si se hubiera elegido antes); en la base ya está desactivado.
    function desactivadoEnLaBase(): Usuario
    {
        $chofer = choferEnTurno(-34.601, -58.381);
        Usuario::whereKey($chofer->id)->update(['activo' => false]);

        return $chofer;
    }

    it('no se le ofrece el viaje', function () {
        $chofer = desactivadoEnLaBase();
        $viaje = viajeDe(Usuario::factory()->create());

        app(Despachador::class)->pedirA($viaje, $chofer);

        expect(OfertaViaje::count())->toBe(0)
            ->and($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer);
    });

    it('no se le asigna un viaje obligatorio', function () {
        $chofer = desactivadoEnLaBase();
        $viaje = viajeDe(Usuario::factory()->create(), ['obligatorio' => true]);

        expect(app(Asignador::class)->asignar($viaje, $chofer))->toBeFalse()
            ->and($viaje->fresh())->estado->toBe(EstadoViaje::Buscando)->chofer_id->toBeNull();
    });
});
