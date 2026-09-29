<?php

namespace App\Console\Commands;

use App\Enums\EstadoViaje;
use App\Models\PuntoRecorrido;
use App\Models\Viaje;
use App\Servicios\Parametros;
use Illuminate\Console\Command;

class PurgarRecorridos extends Command
{
    protected $signature = 'vehiculos:purgar-recorridos';

    protected $description = 'Borra los recorridos GPS de viajes terminados según el plazo de retención';

    public function handle(Parametros $parametros): int
    {
        $limite = now()->subDays($parametros->entero('retencion_recorrido_dias'));

        $viajes = Viaje::where(fn ($q) => $q
            ->where(fn ($f) => $f->where('estado', EstadoViaje::Finalizado)->where('finalizado_en', '<', $limite))
            ->orWhere(fn ($c) => $c->where('estado', EstadoViaje::Cancelado)->where('cancelado_en', '<', $limite)))
            ->select('id');

        $borrados = PuntoRecorrido::whereIn('viaje_id', $viajes)->delete();
        $this->info("Puntos de recorrido borrados: $borrados");

        return self::SUCCESS;
    }
}
