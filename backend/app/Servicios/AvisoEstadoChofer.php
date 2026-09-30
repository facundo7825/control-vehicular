<?php

namespace App\Servicios;

use App\Events\EstadoChoferActualizado;
use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * El estado del chofer se calcula, no se guarda: esta clase recuerda el último estado publicado
 * y emite EstadoChoferActualizado solo cuando el calculado es distinto.
 */
class AvisoEstadoChofer
{
    public function __construct(private CalculadorEstadoChofer $estados) {}

    /**
     * Todo corre después del commit (o de inmediato si no hay transacción): si la transacción se revierte
     * o se reintenta, ni se guarda el estado ni se emite, y el próximo chequeo lo vuelve a intentar.
     */
    public function publicarSiCambio(Usuario $chofer): void
    {
        DB::afterCommit(function () use ($chofer) {
            $estado = $this->estados->estado($chofer)->value;
            $clave = "estado_chofer_publicado:{$chofer->id}";

            if (Cache::get($clave) === $estado) {
                return;
            }

            Cache::forever($clave, $estado);
            EstadoChoferActualizado::dispatch($chofer->id, $estado);
        });
    }
}
