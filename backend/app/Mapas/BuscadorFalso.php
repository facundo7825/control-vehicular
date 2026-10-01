<?php

namespace App\Mapas;

/** Desarrollo y tests: resultados deterministas, cerca de lat/lng si vienen (por defecto, el Obelisco). */
class BuscadorFalso implements BuscadorLugares
{
    public function buscar(string $texto, ?float $lat, ?float $lng): array
    {
        $lat ??= -34.6037;
        $lng ??= -58.3816;

        return array_map(fn (int $i) => [
            'nombre' => "$texto $i",
            'direccion' => "$texto $i, Buenos Aires, Argentina",
            'lat' => round($lat + 0.001 * $i, 6),
            'lng' => round($lng + 0.001 * $i, 6),
        ], [1, 2, 3]);
    }
}
