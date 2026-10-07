<?php

namespace App\Mapas;

/**
 * Geocodificación inversa: la dirección legible de un punto, para no mostrar coordenadas. Se elige con
 * `LUGARES_DRIVER`: google usa Google, falso el falso y el resto (nominatim, georef o una lista) Nominatim.
 */
interface GeocodificadorInverso
{
    /** Como mucho 2 s de espera. Nunca lanza: ante error, o si el punto no tiene dirección, devuelve null. */
    public function direccion(float $lat, float $lng): ?string;
}
