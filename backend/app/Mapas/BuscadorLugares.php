<?php

namespace App\Mapas;

interface BuscadorLugares
{
    /**
     * Busca lugares por texto (como mucho 5), con sesgo opcional hacia lat/lng.
     *
     * @return list<array{nombre: string, direccion: string, lat: float, lng: float}>  Nunca lanza: ante error devuelve [].
     */
    public function buscar(string $texto, ?float $lat, ?float $lng): array;
}
