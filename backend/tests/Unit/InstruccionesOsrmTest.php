<?php

use App\Mapas\InstruccionesOsrm;

function maniobra(string $type, ?string $modifier = null, array $extra = []): array
{
    return array_filter(['type' => $type, 'modifier' => $modifier], fn ($v) => $v !== null) + $extra;
}

it('traduce los giros con todos los modificadores', function (?string $modifier, string $esperado, string $tipo) {
    expect(InstruccionesOsrm::instruccion(maniobra('turn', $modifier), 'San Martín'))->toBe($esperado)
        ->and(InstruccionesOsrm::tipo(maniobra('turn', $modifier)))->toBe($tipo);
})->with([
    ['right', 'Doblá a la derecha por San Martín', 'derecha'],
    ['left', 'Doblá a la izquierda por San Martín', 'izquierda'],
    ['slight right', 'Doblá levemente a la derecha por San Martín', 'leve_derecha'],
    ['slight left', 'Doblá levemente a la izquierda por San Martín', 'leve_izquierda'],
    ['sharp right', 'Doblá cerrado a la derecha por San Martín', 'cerrado_derecha'],
    ['sharp left', 'Doblá cerrado a la izquierda por San Martín', 'cerrado_izquierda'],
    ['straight', 'Seguí derecho por San Martín', 'recto'],
    ['uturn', 'Pegá la vuelta y seguí por San Martín', 'retorno'],
    [null, 'Seguí derecho por San Martín', 'recto'],
]);

it('sin nombre de calle no agrega "por"', function () {
    expect(InstruccionesOsrm::instruccion(maniobra('turn', 'left'), ''))->toBe('Doblá a la izquierda')
        ->and(InstruccionesOsrm::instruccion(maniobra('turn', 'uturn'), ''))->toBe('Pegá la vuelta')
        ->and(InstruccionesOsrm::instruccion(maniobra('new name', 'straight'), '  '))->toBe('Seguí derecho')
        ->and(InstruccionesOsrm::instruccion(maniobra('roundabout', 'right', ['exit' => 3]), ''))->toBe('En la rotonda, tomá la 3.ª salida');
});

it('traduce seguir, cambio de nombre y continuar', function () {
    expect(InstruccionesOsrm::instruccion(maniobra('new name', 'straight'), 'Belgrano'))->toBe('Seguí por Belgrano')
        ->and(InstruccionesOsrm::instruccion(maniobra('continue', 'straight'), 'Belgrano'))->toBe('Seguí derecho por Belgrano')
        ->and(InstruccionesOsrm::instruccion(maniobra('continue', 'slight left'), 'Belgrano'))->toBe('Seguí levemente a la izquierda por Belgrano')
        ->and(InstruccionesOsrm::instruccion(maniobra('continue', 'uturn'), 'Belgrano'))->toBe('Pegá la vuelta y seguí por Belgrano')
        ->and(InstruccionesOsrm::instruccion(maniobra('notification', 'straight'), 'Belgrano'))->toBe('Seguí derecho por Belgrano');
});

it('traduce la rotonda con número de salida', function () {
    expect(InstruccionesOsrm::instruccion(maniobra('roundabout', 'right', ['exit' => 2]), 'Rivadavia'))->toBe('En la rotonda, tomá la 2.ª salida por Rivadavia')
        ->and(InstruccionesOsrm::instruccion(maniobra('rotary', 'left', ['exit' => 1]), 'Rivadavia'))->toBe('En la rotonda, tomá la 1.ª salida por Rivadavia')
        ->and(InstruccionesOsrm::instruccion(maniobra('roundabout', 'right'), 'Rivadavia'))->toBe('En la rotonda, salí por Rivadavia')
        ->and(InstruccionesOsrm::instruccion(maniobra('roundabout turn', 'left'), 'Rivadavia'))->toBe('En la rotonda, doblá a la izquierda por Rivadavia')
        ->and(InstruccionesOsrm::instruccion(maniobra('exit roundabout', 'right'), 'Rivadavia'))->toBe('Salí de la rotonda por Rivadavia')
        ->and(InstruccionesOsrm::tipo(maniobra('roundabout', 'right', ['exit' => 2])))->toBe('rotonda')
        ->and(InstruccionesOsrm::tipo(maniobra('rotary', 'left')))->toBe('rotonda');
});

it('traduce la salida con o sin rumbo', function () {
    expect(InstruccionesOsrm::instruccion(maniobra('depart', null, ['bearing_after' => 3]), 'Corrientes'))->toBe('Salí hacia el norte por Corrientes')
        ->and(InstruccionesOsrm::instruccion(maniobra('depart', null, ['bearing_after' => 92]), 'Corrientes'))->toBe('Salí hacia el este por Corrientes')
        ->and(InstruccionesOsrm::instruccion(maniobra('depart', null, ['bearing_after' => 225]), ''))->toBe('Salí hacia el sudoeste')
        ->and(InstruccionesOsrm::instruccion(maniobra('depart', null, ['bearing_after' => 350]), ''))->toBe('Salí hacia el norte')
        ->and(InstruccionesOsrm::instruccion(maniobra('depart'), 'Corrientes'))->toBe('Salí por Corrientes')
        ->and(InstruccionesOsrm::instruccion(maniobra('depart'), ''))->toBe('Salí')
        ->and(InstruccionesOsrm::tipo(maniobra('depart', 'right')))->toBe('salida');
});

it('traduce la llegada', function () {
    expect(InstruccionesOsrm::instruccion(maniobra('arrive', 'right'), 'Corrientes'))->toBe('Llegaste a destino')
        ->and(InstruccionesOsrm::tipo(maniobra('arrive', 'right')))->toBe('llegada');
});

it('traduce bifurcaciones, accesos, incorporaciones y fin de calle', function () {
    expect(InstruccionesOsrm::instruccion(maniobra('fork', 'slight right'), 'Panamericana'))->toBe('En la bifurcación, mantenete a la derecha por Panamericana')
        ->and(InstruccionesOsrm::instruccion(maniobra('fork', 'straight'), ''))->toBe('En la bifurcación, seguí derecho')
        ->and(InstruccionesOsrm::instruccion(maniobra('end of road', 'left'), 'Callao'))->toBe('Al final de la calle, doblá a la izquierda por Callao')
        ->and(InstruccionesOsrm::instruccion(maniobra('merge', 'slight left'), 'General Paz'))->toBe('Incorporate a General Paz')
        ->and(InstruccionesOsrm::instruccion(maniobra('on ramp', 'right'), 'Acceso Norte'))->toBe('Tomá el acceso a la derecha por Acceso Norte')
        ->and(InstruccionesOsrm::instruccion(maniobra('off ramp', 'slight left'), ''))->toBe('Tomá la salida a la izquierda')
        ->and(InstruccionesOsrm::tipo(maniobra('fork', 'slight left')))->toBe('leve_izquierda');
});

it('trata tipos desconocidos como un giro', function () {
    expect(InstruccionesOsrm::instruccion(maniobra('algo nuevo', 'right'), 'Lavalle'))->toBe('Doblá a la derecha por Lavalle');
});
