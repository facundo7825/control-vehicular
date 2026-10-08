<?php

namespace App\Mapas;

/**
 * Geocodificación inversa: la dirección legible de un punto, para no mostrar coordenadas. Se elige con
 * `LUGARES_DRIVER`: google usa Google, falso el falso y el resto (nominatim, georef o una lista) Nominatim.
 */
interface GeocodificadorInverso
{
    /** Plazo por defecto de una consulta, con cualquier espera incluida. */
    public const PLAZO_SEG = 2.0;

    /** Si del plazo queda menos que esto, no se llega a consultar. */
    public const PLAZO_MINIMO_SEG = 1.0;

    /**
     * Nunca lanza: ante error, sin plazo suficiente o si el punto no tiene dirección, devuelve null.
     *
     * @param  ?float  $hasta  plazo (microtime) compartido entre varias consultas; sin él, PLAZO_SEG desde ahora.
     */
    public function direccion(float $lat, float $lng, ?float $hasta = null): ?string;
}
