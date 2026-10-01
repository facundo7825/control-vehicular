<?php

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\CalculadorEstadoChofer;

it('está fuera de turno sin turno abierto', function () {
    $chofer = Usuario::factory()->chofer()->create();

    expect(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::FueraDeTurno);
});

it('está libre con turno, señal reciente y sin viajes', function () {
    expect(app(CalculadorEstadoChofer::class)->estado(choferEnTurno()))->toBe(EstadoChofer::Libre);
});

it('queda sin señal si la última ubicación tiene más de 2 minutos y no figura entre los libres', function () {
    $chofer = choferEnTurno(minutos: 3);
    $calc = app(CalculadorEstadoChofer::class);

    expect($calc->estado($chofer))->toBe(EstadoChofer::SinSenal)
        ->and($calc->libres()->pluck('id')->all())->not->toContain($chofer->id);
});

it('está sin señal si nunca envió ubicación', function () {
    $turno = Turno::factory()->create();

    expect(app(CalculadorEstadoChofer::class)->estado($turno->chofer))->toBe(EstadoChofer::SinSenal);
});

it('está en viaje con un viaje activo', function () {
    $chofer = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Llego]);

    expect(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::EnViaje);
});

it('está reservado pronto con una reserva en los próximos 45 minutos', function () {
    $chofer = choferEnTurno();
    Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado,
        'tipo' => TipoViaje::Reserva, 'programado_para' => now()->addMinutes(30),
    ]);

    expect(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::ReservadoPronto);
});

it('sigue libre con una reserva dentro de 3 horas', function () {
    $chofer = choferEnTurno();
    Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado,
        'tipo' => TipoViaje::Reserva, 'programado_para' => now()->addHours(3),
    ]);

    expect(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::Libre);
});

it('lista choferes en turno con su estado', function () {
    $libre = choferEnTurno();
    $ocupado = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $ocupado->id, 'estado' => EstadoViaje::EnCurso]);
    Usuario::factory()->chofer()->create();

    $mapa = app(CalculadorEstadoChofer::class)->choferesEnTurno()
        ->mapWithKeys(fn ($f) => [$f['chofer']->id => $f['estado']]);

    expect($mapa->all())->toBe([$libre->id => EstadoChofer::Libre, $ocupado->id => EstadoChofer::EnViaje]);
});

it('el listado agrupado calcula cada estado igual que estado() chofer por chofer', function () {
    $pronto = choferEnTurno();
    reservaAceptada($pronto, now()->addMinutes(30)); // dentro de los 45 min de bloqueo
    $lejos = choferEnTurno();
    reservaAceptada($lejos, now()->addHours(3));
    $sinUbicacion = Turno::factory()->create()->chofer; // nunca mandó ubicación
    $reservaYaEnHora = choferEnTurno();
    reservaAceptada($reservaYaEnHora, now()->subMinutes(5)); // aceptada y ya debida: ocupa al chofer
    $calc = app(CalculadorEstadoChofer::class);

    $agrupado = $calc->choferesEnTurno()->mapWithKeys(fn ($f) => [$f['chofer']->id => $f['estado']])->all();

    expect($agrupado)->toBe([
        $pronto->id => EstadoChofer::ReservadoPronto,
        $lejos->id => EstadoChofer::Libre,
        $sinUbicacion->id => EstadoChofer::SinSenal,
        $reservaYaEnHora->id => EstadoChofer::EnViaje,
    ]);
    foreach ([$pronto, $lejos, $sinUbicacion, $reservaYaEnHora] as $chofer) {
        expect($calc->estado($chofer))->toBe($agrupado[$chofer->id]);
    }
});
