<?php

use App\Enums\EstadoViaje;
use App\Events\EstadoChoferActualizado;
use App\Events\OfertaCreada;
use App\Events\UbicacionChoferActualizada;
use App\Events\ViajeActualizado;
use App\Models\Viaje;
use App\Servicios\Despachador;
use App\Servicios\MaquinaEstadosViaje;
use App\Servicios\ServicioUbicacion;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('emite ViajeActualizado y el estado del chofer al cambiar de estado', function () {
    Event::fake([ViajeActualizado::class, EstadoChoferActualizado::class]);
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);

    app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::EnCamino);

    Event::assertDispatched(ViajeActualizado::class, fn ($e) => $e->viaje->id === $viaje->id
        && collect($e->broadcastOn())->map->name->sort()->values()->all()
            === ["private-chofer.{$chofer->id}", "private-viaje.{$viaje->id}"]);
    Event::assertDispatched(EstadoChoferActualizado::class,
        fn ($e) => $e->choferId === $chofer->id && $e->estado === 'en_viaje');
});

it('avisa al chofer anterior cuando se lo desasigna', function () {
    Event::fake([ViajeActualizado::class]);
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);

    app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::Buscando, ['chofer_id' => null]);

    Event::assertDispatched(ViajeActualizado::class, fn ($e) => $e->choferAnteriorId === $chofer->id
        && collect($e->broadcastOn())->map->name->contains("private-chofer.{$chofer->id}"));
});

it('emite OfertaCreada al chofer', function () {
    Event::fake([OfertaCreada::class]);
    $chofer = choferEnTurno();

    app(Despachador::class)->despachar(Viaje::factory()->create());

    Event::assertDispatched(OfertaCreada::class, fn ($e) => $e->oferta->chofer_id === $chofer->id
        && $e->broadcastOn()[0]->name === "private-chofer.{$chofer->id}");
});

it('emite la ubicación al mapa y al canal del viaje activo', function () {
    Event::fake([UbicacionChoferActualizada::class]);
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::EnCamino]);

    app(ServicioUbicacion::class)->registrar($chofer, [
        ['lat' => -34.61, 'lng' => -58.39, 'registrado_en' => now()->toIso8601String()],
    ]);

    Event::assertDispatched(UbicacionChoferActualizada::class, fn ($e) => $e->viajeId === $viaje->id
        && collect($e->broadcastOn())->map->name->all() === ['private-mapa.choferes', "private-viaje.{$viaje->id}"]);
});

it('emite el estado del chofer al iniciar turno', function () {
    Event::fake([EstadoChoferActualizado::class]);
    $chofer = App\Models\Usuario::factory()->chofer()->create();

    app(App\Servicios\ServicioTurnos::class)->iniciar($chofer, App\Models\Vehiculo::factory()->create()->id);

    Event::assertDispatched(EstadoChoferActualizado::class, fn ($e) => $e->choferId === $chofer->id);
});

it('un Reverb caído no hace fallar el request: el evento va a la cola', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'clave',
        'broadcasting.connections.reverb.secret' => 'secreto',
        'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => 1,
    ]);
    $turno = App\Models\Turno::factory()->create();

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => -34.60, 'lng' => -58.38, 'registrado_en' => now()->toIso8601String()],
    ]])->assertNoContent();

    Queue::assertPushed(Illuminate\Broadcasting\BroadcastEvent::class,
        fn ($job) => $job->event instanceof UbicacionChoferActualizada);
});

it('avisa estado libre una sola vez cuando empieza a llegar la ubicación de un chofer sin señal', function () {
    $chofer = choferEnTurno(minutos: 10);
    app(App\Servicios\AvisoEstadoChofer::class)->publicarSiCambio($chofer);
    Event::fake([EstadoChoferActualizado::class]);

    $punto = fn () => ['lat' => -34.6, 'lng' => -58.38, 'registrado_en' => now()->toIso8601String()];
    app(ServicioUbicacion::class)->registrar($chofer, [$punto()]);
    $this->travel(10)->seconds();
    app(ServicioUbicacion::class)->registrar($chofer, [$punto()]);

    Event::assertDispatchedTimes(EstadoChoferActualizado::class, 1);
    Event::assertDispatched(EstadoChoferActualizado::class,
        fn ($e) => $e->choferId === $chofer->id && $e->estado === 'libre');
});

it('el comando por minuto avisa sin_senal cuando la ubicación se vuelve vieja, y no lo repite', function () {
    $this->travelTo(now()->startOfMinute());
    $chofer = choferEnTurno();
    $this->artisan('vehiculos:publicar-estados-chofer')->assertSuccessful();
    Event::fake([EstadoChoferActualizado::class]);

    $this->travel(3)->minutes();
    $this->artisan('vehiculos:publicar-estados-chofer')->assertSuccessful();
    $this->travel(1)->minutes();
    $this->artisan('vehiculos:publicar-estados-chofer')->assertSuccessful();

    Event::assertDispatchedTimes(EstadoChoferActualizado::class, 1);
    Event::assertDispatched(EstadoChoferActualizado::class,
        fn ($e) => $e->choferId === $chofer->id && $e->estado === 'sin_senal');
});

it('el comando avisa reservado_pronto al entrar la reserva en la ventana', function () {
    $this->travelTo(now()->startOfMinute());
    $chofer = choferEnTurno();
    $this->artisan('vehiculos:publicar-estados-chofer')->assertSuccessful();
    reservaAceptada($chofer, now()->addMinutes(60));
    Event::fake([EstadoChoferActualizado::class]);

    $this->artisan('vehiculos:publicar-estados-chofer');
    Event::assertNotDispatched(EstadoChoferActualizado::class);

    $this->travel(20)->minutes();
    $chofer->ubicacion()->update(['actualizado_en' => now()]);
    $this->artisan('vehiculos:publicar-estados-chofer');
    Event::assertDispatched(EstadoChoferActualizado::class, fn ($e) => $e->estado === 'reservado_pronto');
});

it('no emite nada si el estado no cambió', function () {
    $chofer = choferEnTurno();
    $aviso = app(App\Servicios\AvisoEstadoChofer::class);
    $aviso->publicarSiCambio($chofer);
    Event::fake([EstadoChoferActualizado::class]);

    $aviso->publicarSiCambio($chofer);
    $this->artisan('vehiculos:publicar-estados-chofer');

    Event::assertNotDispatched(EstadoChoferActualizado::class);
});

it('si la transacción se revierte no se guarda ni se emite el estado', function () {
    $chofer = choferEnTurno();
    Event::fake([EstadoChoferActualizado::class]);

    try {
        Illuminate\Support\Facades\DB::transaction(function () use ($chofer) {
            app(App\Servicios\AvisoEstadoChofer::class)->publicarSiCambio($chofer);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Illuminate\Support\Facades\Cache::has("estado_chofer_publicado:{$chofer->id}"))->toBeFalse();
    Event::assertNotDispatched(EstadoChoferActualizado::class);

    Illuminate\Support\Facades\DB::transaction(fn () => app(App\Servicios\AvisoEstadoChofer::class)->publicarSiCambio($chofer));

    expect(Illuminate\Support\Facades\Cache::get("estado_chofer_publicado:{$chofer->id}"))->toBe('libre');
    Event::assertDispatchedTimes(EstadoChoferActualizado::class, 1);
});

it('el comando sigue con los demás choferes si uno falla', function () {
    $a = choferEnTurno();
    $b = choferEnTurno();
    Event::fake([EstadoChoferActualizado::class]);
    $this->mock(App\Servicios\AvisoEstadoChofer::class, function ($m) use ($a, $b) {
        $m->shouldReceive('publicarSiCambio')->with(Mockery::on(fn ($c) => $c->id === $a->id))->andThrow(new RuntimeException('x'));
        $m->shouldReceive('publicarSiCambio')->with(Mockery::on(fn ($c) => $c->id === $b->id))->once();
    });

    $this->artisan('vehiculos:publicar-estados-chofer')->assertSuccessful();
});
