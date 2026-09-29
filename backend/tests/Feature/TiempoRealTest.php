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
