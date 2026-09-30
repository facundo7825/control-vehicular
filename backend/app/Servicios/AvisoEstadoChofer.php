<?php

namespace App\Servicios;

use App\Events\EstadoChoferActualizado;
use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;

/**
 * El estado del chofer se calcula, no se guarda: esta clase recuerda el último estado publicado
 * y emite EstadoChoferActualizado solo cuando el calculado es distinto.
 */
class AvisoEstadoChofer
{
    public function __construct(private CalculadorEstadoChofer $estados) {}

    public function publicarSiCambio(Usuario $chofer): void
    {
        $estado = $this->estados->estado($chofer)->value;
        $clave = "estado_chofer_publicado:{$chofer->id}";

        if (Cache::get($clave) === $estado) {
            return;
        }

        Cache::forever($clave, $estado);
        EstadoChoferActualizado::dispatch($chofer->id, $estado);
    }
}
