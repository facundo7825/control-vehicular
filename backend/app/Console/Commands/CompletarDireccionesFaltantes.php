<?php

namespace App\Console\Commands;

use App\Mapas\BuscadorNominatim;
use App\Models\Viaje;
use App\Servicios\CompletadorDirecciones;
use Illuminate\Console\Command;

/**
 * Manual (no está en el schedule): completa con la geocodificación inversa las direcciones que les faltan a los
 * viajes ya guardados, los más nuevos primero y en lotes. Con el Nominatim público el geocodificador espera 1 s
 * entre pedidos. Un punto sin dirección (en medio del campo) no detiene nada; si el geocodificador entra en su
 * corte de 60 s tras una falla (servicio caído o que nos bloqueó), se detiene.
 */
class CompletarDireccionesFaltantes extends Command
{
    protected $signature = 'vehiculos:completar-direcciones {--limite=200 : Cuántos viajes revisar como mucho}';

    protected $description = 'Completa la dirección del origen y del destino de los viajes que no la tienen';

    private const LOTE = 50;

    public function handle(CompletadorDirecciones $completador): int
    {
        $limite = (int) $this->option('limite');
        if ($limite < 1) {
            $this->error('El límite tiene que ser un número mayor que 0.');

            return self::INVALID;
        }

        $completos = 0;
        $incompletos = 0;

        $viajes = Viaje::where(fn ($q) => $q->whereNull('origen_direccion')->orWhereNull('destino_direccion'))
            ->lazyByIdDesc(self::LOTE)
            ->take($limite);

        foreach ($viajes as $viaje) {
            $completador->completarViaje($viaje);
            $completador->faltan($viaje) ? $incompletos++ : $completos++;

            if (BuscadorNominatim::cortado()) {
                $this->warn('El servicio de direcciones falló y quedó en pausa por 60 s: se detiene. Probá de nuevo en unos minutos.');
                break;
            }
        }

        $this->info("Viajes completos: $completos. Sin dirección todavía: $incompletos.");

        return self::SUCCESS;
    }
}
