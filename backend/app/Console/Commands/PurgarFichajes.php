<?php

namespace App\Console\Commands;

use App\Models\EventoAsistencia;
use Illuminate\Console\Command;

class PurgarFichajes extends Command
{
    /** Los fichajes de un id_externo sin usuario (personas que no usan la app) se guardan menos. */
    public const DIAS_SIN_USUARIO = 7;

    protected $signature = 'vehiculos:purgar-fichajes';

    protected $description = 'Borra los fichajes del control de asistencia según el plazo de retención';

    public function handle(): int
    {
        $retencion = max(1, (int) config('vehiculos.asistencia.retencion_dias'));

        $borrados = EventoAsistencia::where('created_at', '<', now()->subDays($retencion))->delete()
            + EventoAsistencia::whereNull('usuario_id')->where('created_at', '<', now()->subDays(self::DIAS_SIN_USUARIO))->delete();

        $this->info("Fichajes borrados: $borrados");

        return self::SUCCESS;
    }
}
