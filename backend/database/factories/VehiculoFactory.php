<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class VehiculoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patente' => strtoupper(fake()->unique()->bothify('??###??')),
            'marca' => 'Toyota',
            'modelo' => 'Corolla',
            'color' => 'Blanco',
            'activo' => true,
        ];
    }
}
