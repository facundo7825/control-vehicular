<?php

use App\Enums\EstadoViaje;
use App\Models\PuntoRecorrido;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;

it('simula choferes en turno con ubicación', function () {
    $this->artisan('simular:choferes', ['cantidad' => 3, '--iteraciones' => 2, '--intervalo' => 0])
        ->assertSuccessful();

    expect(Usuario::where('id_externo', 'like', 'sim-chofer-%')->count())->toBe(3)
        ->and(Turno::whereNull('fin')->count())->toBe(3)
        ->and(UbicacionChofer::count())->toBe(3);
});

it('reutiliza los choferes simulados en otra corrida', function () {
    $this->artisan('simular:choferes', ['cantidad' => 2, '--iteraciones' => 1, '--intervalo' => 0]);
    $this->artisan('simular:choferes', ['cantidad' => 2, '--iteraciones' => 1, '--intervalo' => 0])->assertSuccessful();

    expect(Usuario::where('id_externo', 'like', 'sim-chofer-%')->count())->toBe(2)
        ->and(Turno::whereNull('fin')->count())->toBe(2);
});

it('purga recorridos de viajes terminados hace más de 90 días', function () {
    $viejo = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()->subDays(91)]);
    $reciente = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()->subDays(10)]);
    foreach ([$viejo, $reciente] as $v) {
        PuntoRecorrido::create(['viaje_id' => $v->id, 'lat' => 1, 'lng' => 1, 'registrado_en' => now()]);
    }

    $this->artisan('vehiculos:purgar-recorridos')->assertSuccessful();

    expect(PuntoRecorrido::pluck('viaje_id')->all())->toBe([$reciente->id]);
});
