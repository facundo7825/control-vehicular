<?php

namespace App\Console\Commands;

use App\Servicios\AlertasSinSenal;
use Illuminate\Console\Command;

class AlertarSinSenal extends Command
{
    protected $signature = 'vehiculos:alertar-sin-senal';

    protected $description = 'Alerta al panel por choferes sin señal durante un viaje y resuelve las que ya no aplican';

    public function handle(AlertasSinSenal $alertas): int
    {
        $r = $alertas->revisar();
        $this->info("Alertas creadas: {$r['creadas']}. Resueltas: {$r['resueltas']}.");

        return self::SUCCESS;
    }
}
