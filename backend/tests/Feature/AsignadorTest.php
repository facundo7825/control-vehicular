<?php

use App\Enums\EstadoViaje;
use App\Mapas\ServicioMapas;
use App\Models\Parametro;
use App\Models\Viaje;
use App\Servicios\Asignador;

function candidatos(array $choferes)
{
    return collect($choferes)->each->load('ubicacion');
}

it('ordena por cercanía al origen del viaje', function () {
    $viaje = Viaje::factory()->create(['origen_lat' => -34.600, 'origen_lng' => -58.380]);
    $lejos = choferEnTurno(-34.700, -58.480);
    $cerca = choferEnTurno(-34.601, -58.381);
    $medio = choferEnTurno(-34.620, -58.400);

    $orden = app(Asignador::class)->ordenarPorCercania($viaje, candidatos([$lejos, $cerca, $medio]));

    expect($orden->pluck('id')->all())->toBe([$cerca->id, $medio->id, $lejos->id]);
});

it('prioriza el tiempo de llegada de Google sobre la distancia en línea recta', function () {
    $viaje = Viaje::factory()->create(['origen_lat' => -34.600, 'origen_lng' => -58.380]);
    $cerca = choferEnTurno(-34.601, -58.381);
    $medio = choferEnTurno(-34.620, -58.400);
    $this->app->instance(ServicioMapas::class, new class implements ServicioMapas {
        public function duracionesHacia(array $origenes, float $lat, float $lng): array
        {
            // El más cercano en línea recta tarda más (p. ej., está del otro lado de una autopista).
            return array_map(fn ($o) => $o[0] === -34.601 ? 900 : 200, $origenes);
        }

        public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
        {
            return null;
        }
    });

    $orden = app(Asignador::class)->ordenarPorCercania($viaje, candidatos([$cerca, $medio]));

    expect($orden->pluck('id')->all())->toBe([$medio->id, $cerca->id]);
});

it('consulta a Google solo los N más cercanos y agrega el resto al final', function () {
    Parametro::create(['clave' => 'candidatos_distance_matrix', 'valor' => '2']);
    $viaje = Viaje::factory()->create(['origen_lat' => -34.600, 'origen_lng' => -58.380]);
    $a = choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.610, -58.390);
    $c = choferEnTurno(-34.700, -58.480);
    $espia = new class implements ServicioMapas {
        public array $consultados = [];

        public function duracionesHacia(array $origenes, float $lat, float $lng): array
        {
            $this->consultados = array_keys($origenes);

            return array_map(fn () => null, $origenes);
        }

        public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
        {
            return null;
        }
    };
    $this->app->instance(ServicioMapas::class, $espia);

    $orden = app(Asignador::class)->ordenarPorCercania($viaje, candidatos([$c, $b, $a]));

    expect($espia->consultados)->toBe([$a->id, $b->id])
        ->and($orden->pluck('id')->all())->toBe([$a->id, $b->id, $c->id]);
});

it('asigna chofer y vehículo y pasa el viaje a aceptado', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create();

    expect(app(Asignador::class)->asignar($viaje, $chofer))->toBeTrue()
        ->and($viaje->estado)->toBe(EstadoViaje::Aceptado)
        ->and($viaje->chofer_id)->toBe($chofer->id)
        ->and($viaje->vehiculo_id)->toBe($chofer->turnoAbierto->vehiculo_id);
});

it('no asigna el mismo chofer a dos viajes', function () {
    $chofer = choferEnTurno();
    $primero = Viaje::factory()->create();
    $segundo = Viaje::factory()->create();

    expect(app(Asignador::class)->asignar($primero, $chofer))->toBeTrue()
        ->and(app(Asignador::class)->asignar($segundo, $chofer))->toBeFalse()
        ->and($segundo->fresh()->estado)->toBe(EstadoViaje::Buscando)
        ->and($segundo->fresh()->chofer_id)->toBeNull();
});

it('no asigna un viaje que ya fue cancelado', function () {
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Cancelado]);

    expect(app(Asignador::class)->asignar($viaje, choferEnTurno()))->toBeFalse();
});
