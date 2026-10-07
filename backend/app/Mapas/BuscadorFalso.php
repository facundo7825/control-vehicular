<?php

namespace App\Mapas;

/** Desarrollo y tests: resultados deterministas, cerca de lat/lng si vienen (por defecto, el Obelisco). */
class BuscadorFalso implements BuscadorLugares, GeocodificadorInverso
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

    /** Una calle inventada que depende del punto (sin mostrar sus coordenadas): "Calle Falsa 123, Ciudad de prueba". */
    public function direccion(float $lat, float $lng): ?string
    {
        $altura = (abs((int) round($lat * 10000)) + abs((int) round($lng * 10000))) % 1000 + 1;

        return "Calle Falsa $altura, Ciudad de prueba";
    }
}
