<?php

use App\Enums\EstadoViaje;
use App\Models\Alerta;
use App\Models\Parametro;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-01 12:00:00')));

function viajeActivoDe(Usuario $chofer, EstadoViaje $estado = EstadoViaje::EnCurso): Viaje
{
    return Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => $estado, 'aceptado_en' => now()]);
}

it('alerta al panel si un chofer con viaje activo lleva más de 10 minutos sin señal', function () {
    $chofer = choferEnTurno(minutos: 11);
    $viaje = viajeActivoDe($chofer);
    viajeActivoDe(choferEnTurno(minutos: 9));          // todavía no llega al umbral
    choferEnTurno(minutos: 30);                         // sin viaje activo: no es alerta

    $this->artisan('vehiculos:alertar-sin-senal')->assertSuccessful();

    $alerta = Alerta::sole();
    expect($alerta)
        ->tipo->toBe(Alerta::CHOFER_SIN_SENAL)
        ->viaje_id->toBe($viaje->id)
        ->chofer_id->toBe($chofer->id)
        ->resuelta_en->toBeNull()
        ->and($alerta->mensaje)->toBe("{$chofer->nombre} no envía su ubicación desde las 08:49 y tiene el viaje #{$viaje->id} activo.");
});

it('no duplica la alerta mientras siga sin señal', function () {
    viajeActivoDe(choferEnTurno(minutos: 15));

    $this->artisan('vehiculos:alertar-sin-senal');
    $this->artisan('vehiculos:alertar-sin-senal');

    expect(Alerta::count())->toBe(1);
});

it('resuelve la alerta cuando vuelve la señal', function () {
    $chofer = choferEnTurno(minutos: 15);
    viajeActivoDe($chofer);
    $this->artisan('vehiculos:alertar-sin-senal');

    UbicacionChofer::whereKey($chofer->id)->update(['actualizado_en' => now()]);
    $this->artisan('vehiculos:alertar-sin-senal');

    expect(Alerta::sole()->resuelta_en)->not->toBeNull()
        ->and(Alerta::pendientes()->count())->toBe(0);
});

it('resuelve la alerta cuando el viaje termina', function () {
    $viaje = viajeActivoDe(choferEnTurno(minutos: 15));
    $this->artisan('vehiculos:alertar-sin-senal');

    $viaje->update(['estado' => EstadoViaje::Finalizado]);
    $this->artisan('vehiculos:alertar-sin-senal');

    expect(Alerta::pendientes()->count())->toBe(0);
});

it('no alerta por una reserva aceptada que todavía no empezó', function () {
    reservaAceptada(choferEnTurno(minutos: 15), now()->addHours(3));

    $this->artisan('vehiculos:alertar-sin-senal');

    expect(Alerta::count())->toBe(0);
});

it('usa el umbral configurable no_disponible_min', function () {
    Parametro::create(['clave' => 'no_disponible_min', 'valor' => '20']);
    viajeActivoDe(choferEnTurno(minutos: 15));

    $this->artisan('vehiculos:alertar-sin-senal');

    expect(Alerta::count())->toBe(0);
});

it('corre cada minuto', function () {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'vehiculos:alertar-sin-senal'));

    expect($evento?->expression)->toBe('* * * * *');
});
