<?php

namespace Database\Factories;

use App\Enums\OrigenTurno;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Illuminate\Database\Eloquent\Factories\Factory;

class TurnoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'chofer_id' => Usuario::factory()->chofer(),
            'vehiculo_id' => Vehiculo::factory(),
            'inicio' => now()->subHours(2),
            'fin' => null,
            'origen' => OrigenTurno::Manual,
        ];
    }
}
