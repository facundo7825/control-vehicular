<?php

namespace App\Console\Commands;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use App\Servicios\AvisoEstadoChofer;
use Illuminate\Console\Command;

class PublicarEstadosChofer extends Command
{
    protected $signature = 'vehiculos:publicar-estados-chofer';

    protected $description = 'Avisa en tiempo real los cambios de estado de los choferes en turno que no vienen de una acción (sin señal, reserva próxima)';

    public function handle(AvisoEstadoChofer $aviso): int
    {
        Usuario::where('rol', RolUsuario::Chofer)
            ->whereHas('turnoAbierto')
            ->each(fn (Usuario $chofer) => $aviso->publicarSiCambio($chofer));

        return self::SUCCESS;
    }
}
