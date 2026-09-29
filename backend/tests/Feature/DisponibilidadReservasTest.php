<?php

use App\Enums\EstadoViaje;
use App\Models\Usuario;
use App\Servicios\DisponibilidadReservas;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-01 12:00:00')));

function disponibilidad(): DisponibilidadReservas
{
    return app(DisponibilidadReservas::class);
}

it('estima la duración con la ruta de Google más el margen', function () {
    fijarDuracionRuta(1501); // 25 min y 1 s: se redondea a 26

    expect(disponibilidad()->duracionEstimada(-34.60, -58.38, -34.61, -58.39))->toBe(41);
});

it('usa la duración por defecto si Google no tiene dato', function () {
    fijarDuracionRuta(null);

    expect(disponibilidad()->duracionEstimada(-34.60, -58.38, -34.61, -58.39))->toBe(60);
});

it('detecta la superposición con colchón justo en los bordes', function (string $hora, int $minutos, bool $esperado) {
    // Reserva existente de 10:00 a 11:00; con 30 min de colchón ocupa de 09:30 a 11:30.
    $seSuperponen = DisponibilidadReservas::seSuperponen(
        Carbon::parse("2026-10-02 $hora"), $minutos, Carbon::parse('2026-10-02 10:00'), 60, 30,
    );

    expect($seSuperponen)->toBe($esperado);
})->with([
    'empieza justo al terminar el colchón' => ['11:30', 60, false],
    'empieza un minuto antes' => ['11:29', 60, true],
    'termina justo cuando empieza el colchón' => ['08:30', 60, false],
    'termina un minuto después' => ['08:31', 60, true],
    'queda adentro' => ['10:15', 10, true],
    'la envuelve' => ['07:00', 300, true],
]);

it('solo ocupan al chofer sus reservas tomadas que se superponen', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $inicio = Carbon::parse('2026-10-02 10:00');
    reservaAceptada(Usuario::factory()->chofer()->create(), $inicio); // de otro chofer
    foreach ([EstadoViaje::Cancelado, EstadoViaje::Finalizado, EstadoViaje::SinChofer] as $estado) {
        reservaAceptada($chofer, $inicio, attrs: ['estado' => $estado]);
    }
    reservaAceptada($chofer, Carbon::parse('2026-10-02 12:00')); // fuera del colchón (termina 11:30)

    expect(disponibilidad()->estaDisponible($chofer->id, $inicio, 60))->toBeTrue();

    $enCurso = reservaAceptada($chofer, Carbon::parse('2026-10-02 09:00'), attrs: ['estado' => EstadoViaje::EnCurso]);

    expect(disponibilidad()->estaDisponible($chofer->id, $inicio, 60))->toBeFalse()
        ->and(disponibilidad()->estaDisponible($chofer->id, $inicio, 60, excluirViajeId: $enCurso->id))->toBeTrue();
});

it('usa la duración por defecto para reservas sin duración guardada', function () {
    $chofer = Usuario::factory()->chofer()->create();
    reservaAceptada($chofer, Carbon::parse('2026-10-02 10:00'), attrs: ['duracion_estimada_min' => null]);

    // 10:00 + 60 min por defecto + 30 de colchón: 11:29 choca, 11:30 no.
    expect(disponibilidad()->estaDisponible($chofer->id, Carbon::parse('2026-10-02 11:29'), 30))->toBeFalse()
        ->and(disponibilidad()->estaDisponible($chofer->id, Carbon::parse('2026-10-02 11:30'), 30))->toBeTrue();
});

it('lista los choferes activos libres en la franja, primero los de menos reservas ese día', function () {
    $inicio = Carbon::parse('2026-10-02 15:00'); // 12:00 en Buenos Aires
    $a = Usuario::factory()->chofer()->create();
    reservaAceptada($a, Carbon::parse('2026-10-02 11:00'));
    reservaAceptada($a, Carbon::parse('2026-10-02 20:00'));
    $b = Usuario::factory()->chofer()->create();
    reservaAceptada($b, Carbon::parse('2026-10-02 02:00')); // 23:00 del día anterior en Buenos Aires
    $c = Usuario::factory()->chofer()->create();
    reservaAceptada($c, Carbon::parse('2026-10-03 02:00')); // 23:00 del mismo día en Buenos Aires
    $ocupado = Usuario::factory()->chofer()->create();
    reservaAceptada($ocupado, Carbon::parse('2026-10-02 16:00'));
    Usuario::factory()->chofer()->create(['activo' => false]);
    Usuario::factory()->create(); // solicitante
    $g = Usuario::factory()->chofer()->create();

    $lista = disponibilidad()->choferesDisponibles($inicio, 60);

    expect($lista->map(fn (array $f) => [$f['chofer']->id, $f['reservas_del_dia']])->all())
        ->toBe([[$b->id, 0], [$g->id, 0], [$c->id, 1], [$a->id, 2]]);
});
