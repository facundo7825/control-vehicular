<?php

namespace App\Console\Commands;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Servicios\ServicioTurnos;
use App\Servicios\ServicioUbicacion;
use Illuminate\Console\Command;

class SimularChoferes extends Command
{
    protected $signature = 'simular:choferes {cantidad=5} {--lat=-34.6037} {--lng=-58.3816} {--iteraciones=0} {--intervalo=5}';

    protected $description = 'Crea choferes simulados en turno y los mueve por la ciudad (solo desarrollo)';

    public function handle(ServicioTurnos $turnos, ServicioUbicacion $ubicacion): int
    {
        if (app()->isProduction()) {
            $this->error('El simulador no puede correr en producción.');

            return self::FAILURE;
        }

        $choferes = collect(range(1, (int) $this->argument('cantidad')))->map(function (int $n) use ($turnos) {
            $chofer = Usuario::firstOrCreate(
                ['id_externo' => "sim-chofer-$n"],
                ['nombre' => "Chofer simulado $n", 'cargo' => 'Chofer', 'rol' => RolUsuario::Chofer],
            );
            if (! $chofer->turnoAbierto()->exists()) {
                $vehiculo = Vehiculo::firstOrCreate(
                    ['patente' => sprintf('SIM%03d', $n)],
                    ['marca' => 'Toyota', 'modelo' => 'Corolla', 'color' => 'Blanco'],
                );
                $turnos->iniciar($chofer, $vehiculo->id);
            }

            return $chofer;
        });

        // Posición inicial aleatoria en un radio de ~3 km del centro.
        $posiciones = $choferes->mapWithKeys(fn (Usuario $c) => [$c->id => [
            (float) $this->option('lat') + mt_rand(-2700, 2700) / 100000,
            (float) $this->option('lng') + mt_rand(-2700, 2700) / 100000,
        ]])->all();

        $iteraciones = (int) $this->option('iteraciones');
        for ($i = 0; $iteraciones === 0 || $i < $iteraciones; $i++) {
            foreach ($choferes as $chofer) {
                // Paso de ~50 m por iteración.
                $posiciones[$chofer->id][0] += mt_rand(-45, 45) / 100000;
                $posiciones[$chofer->id][1] += mt_rand(-45, 45) / 100000;
                $ubicacion->registrar($chofer, [[
                    'lat' => $posiciones[$chofer->id][0],
                    'lng' => $posiciones[$chofer->id][1],
                    'rumbo' => mt_rand(0, 359),
                    'registrado_en' => now()->toIso8601String(),
                ]]);
            }
            $this->line("Iteración $i: {$choferes->count()} choferes movidos.");
            sleep((int) $this->option('intervalo'));
        }

        return self::SUCCESS;
    }
}
