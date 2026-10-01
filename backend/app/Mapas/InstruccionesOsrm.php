<?php

namespace App\Mapas;

/**
 * Arma la indicación en castellano rioplatense de una maniobra de OSRM (`type`, `modifier`, `exit`,
 * `bearing_after`) y el nombre de la calle por la que se sigue (puede venir vacío).
 */
final class InstruccionesOsrm
{
    /** Modificador de OSRM => [tipo, "a la derecha" con su matiz, lado]. */
    private const MODIFICADORES = [
        'right' => ['derecha', 'a la derecha', 'derecha'],
        'left' => ['izquierda', 'a la izquierda', 'izquierda'],
        'slight right' => ['leve_derecha', 'levemente a la derecha', 'derecha'],
        'slight left' => ['leve_izquierda', 'levemente a la izquierda', 'izquierda'],
        'sharp right' => ['cerrado_derecha', 'cerrado a la derecha', 'derecha'],
        'sharp left' => ['cerrado_izquierda', 'cerrado a la izquierda', 'izquierda'],
    ];

    private const RUMBOS = ['norte', 'noreste', 'este', 'sudeste', 'sur', 'sudoeste', 'oeste', 'noroeste'];

    /** @param  array{type?: string, modifier?: string, exit?: int, bearing_after?: int|float}  $maniobra */
    public static function instruccion(array $maniobra, string $calle): string
    {
        $tipo = (string) ($maniobra['type'] ?? 'turn');
        $mod = (string) ($maniobra['modifier'] ?? 'straight');
        $calle = trim($calle);
        $por = $calle === '' ? '' : " por $calle";
        [, $hacia, $lado] = self::MODIFICADORES[$mod] ?? [null, null, null];

        switch ($tipo) {
            case 'depart':
                if (isset($maniobra['bearing_after'])) {
                    $i = (int) round(fmod((float) $maniobra['bearing_after'], 360) / 45) % 8;

                    return 'Salí hacia el '.self::RUMBOS[$i].$por;
                }

                return 'Salí'.$por;
            case 'arrive':
                return 'Llegaste a destino';
            case 'roundabout':
            case 'rotary':
                if (isset($maniobra['exit']) && (int) $maniobra['exit'] > 0) {
                    return 'En la rotonda, tomá la '.(int) $maniobra['exit'].'.ª salida'.$por;
                }

                return $calle === '' ? 'Entrá en la rotonda' : "En la rotonda, salí$por";
            case 'roundabout turn':
                return 'En la rotonda, '.lcfirst(self::girar($mod, $hacia, $calle));
            case 'exit roundabout':
            case 'exit rotary':
                return 'Salí de la rotonda'.$por;
            case 'new name':
                return $calle === '' ? 'Seguí derecho' : "Seguí$por";
            case 'continue':
            case 'notification':
                if ($mod === 'uturn') {
                    return self::girar($mod, $hacia, $calle);
                }

                return 'Seguí '.($hacia ?? 'derecho').$por;
            case 'fork':
                return 'En la bifurcación, '.($lado === null ? 'seguí derecho' : "mantenete a la $lado").$por;
            case 'end of road':
                return 'Al final de la calle, '.lcfirst(self::girar($mod, $hacia, $calle));
            case 'merge':
                return 'Incorporate'.($calle === '' ? '' : " a $calle");
            case 'on ramp':
                return 'Tomá el acceso'.($lado === null ? '' : " a la $lado").$por;
            case 'off ramp':
                return 'Tomá la salida'.($lado === null ? '' : " a la $lado").$por;
            default: // turn y tipos que OSRM agregue en el futuro.
                return self::girar($mod, $hacia, $calle);
        }
    }

    /**
     * Tipo de maniobra para la app (ícono): salida, llegada, rotonda, retorno, recto, derecha, izquierda,
     * leve_derecha, leve_izquierda, cerrado_derecha o cerrado_izquierda.
     */
    public static function tipo(array $maniobra): string
    {
        $tipo = (string) ($maniobra['type'] ?? 'turn');
        $mod = (string) ($maniobra['modifier'] ?? 'straight');

        return match (true) {
            $tipo === 'depart' => 'salida',
            $tipo === 'arrive' => 'llegada',
            in_array($tipo, ['roundabout', 'rotary', 'roundabout turn', 'exit roundabout', 'exit rotary'], true) => 'rotonda',
            $mod === 'uturn' => 'retorno',
            default => self::MODIFICADORES[$mod][0] ?? 'recto',
        };
    }

    /** "Doblá a la derecha por X", "Pegá la vuelta y seguí por X" o "Seguí derecho por X". */
    private static function girar(string $mod, ?string $hacia, string $calle): string
    {
        if ($mod === 'uturn') {
            return 'Pegá la vuelta'.($calle === '' ? '' : " y seguí por $calle");
        }
        $por = $calle === '' ? '' : " por $calle";

        return ($hacia === null ? 'Seguí derecho' : "Doblá $hacia").$por;
    }
}
