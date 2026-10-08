<?php

namespace App\Servicios;

use App\Jobs\CompletarDirecciones;
use App\Mapas\GeocodificadorInverso;
use App\Models\Viaje;

/**
 * Completa la dirección del origen y del destino de un viaje con la geocodificación inversa, para no mostrar
 * coordenadas. Al crear un viaje se consulta ANTES de la transacción (nunca se retienen locks esperando la red);
 * lo que no se consiga se guarda sin dirección y lo reintenta el job `CompletarDirecciones`.
 */
class CompletadorDirecciones
{
    private const PUNTOS = ['origen', 'destino'];

    public function __construct(private GeocodificadorInverso $geocodificador) {}

    /**
     * Los datos de un pedido con las direcciones que faltaban. Un solo plazo de 2 s para los dos puntos: crear el
     * viaje nunca espera más que eso; lo que no llegue lo completa el job.
     */
    public function completar(array $datos): array
    {
        $hasta = microtime(true) + GeocodificadorInverso::PLAZO_SEG;
        foreach (self::PUNTOS as $punto) {
            if (blank($datos["{$punto}_direccion"] ?? null)) {
                $datos["{$punto}_direccion"] = $this->geocodificador->direccion(
                    (float) $datos["{$punto}_lat"], (float) $datos["{$punto}_lng"], $hasta,
                );
            }
        }

        return $datos;
    }

    /** Si al viaje recién creado le quedó un punto sin dirección, encola el reintento. */
    public function reintentarSiFalta(Viaje $viaje): void
    {
        if ($this->faltan($viaje)) {
            CompletarDirecciones::dispatch($viaje->id)->delay(now()->addSeconds(CompletarDirecciones::ESPERA_SEG));
        }
    }

    /**
     * Completa en la base los puntos sin dirección (sin pisar una dirección que se haya guardado mientras tanto).
     * Fuera del pedido (job y comando): cada punto con su propio plazo.
     *
     * @return bool si se completó alguno
     */
    public function completarViaje(Viaje $viaje): bool
    {
        $nuevas = [];
        foreach (self::PUNTOS as $punto) {
            if (blank($viaje->{"{$punto}_direccion"})) {
                $direccion = $this->geocodificador->direccion($viaje->{"{$punto}_lat"}, $viaje->{"{$punto}_lng"});
                if ($direccion !== null) {
                    $nuevas["{$punto}_direccion"] = $direccion;
                }
            }
        }

        foreach ($nuevas as $campo => $direccion) {
            Viaje::whereKey($viaje->id)->whereNull($campo)->update([$campo => $direccion]);
        }
        if ($nuevas !== []) {
            $viaje->refresh();
        }

        return $nuevas !== [];
    }

    public function faltan(Viaje $viaje): bool
    {
        foreach (self::PUNTOS as $punto) {
            if (blank($viaje->{"{$punto}_direccion"})) {
                return true;
            }
        }

        return false;
    }
}
