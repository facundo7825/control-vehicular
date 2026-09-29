<?php

namespace App\Jobs;

use App\Models\OfertaViaje;
use App\Servicios\Despachador;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VencerOferta implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $ofertaId) {}

    public function handle(Despachador $despachador): void
    {
        if ($oferta = OfertaViaje::find($this->ofertaId)) {
            $despachador->vencer($oferta);
        }
    }
}
