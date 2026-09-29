<?php

namespace Database\Factories;

use App\Enums\RolUsuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class UsuarioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id_externo' => (string) fake()->unique()->numberBetween(1000, 999999),
            'nombre' => fake()->name(),
            'cargo' => 'Empleado',
            'rol' => RolUsuario::Solicitante,
            'telefono' => fake()->phoneNumber(),
            'activo' => true,
        ];
    }

    public function chofer(): static
    {
        return $this->state(['rol' => RolUsuario::Chofer, 'cargo' => 'Chofer']);
    }

    public function admin(): static
    {
        return $this->state(['rol' => RolUsuario::Admin]);
    }
}
