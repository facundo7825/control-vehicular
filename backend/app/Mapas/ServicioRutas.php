<?php

namespace App\Mapas;

interface ServicioRutas
{
    /**
     * Recorrido en auto entre dos puntos, con indicaciones en castellano. `puntos` va como [lat, lng] e `indice`
     * es la posición del punto de cada maniobra dentro de `puntos`.
     *
     * @return ?array{distancia_m: int, duracion_s: int, puntos: list<array{0: float, 1: float}>,
     *     pasos: list<array{instruccion: string, distancia_m: int, indice: int, lat: float, lng: float, tipo: string}>}
     *     Nunca lanza: si no hay recorrido o el servicio falla devuelve null.
     */
    public function ruta(float $oLat, float $oLng, float $dLat, float $dLng): ?array;
}
