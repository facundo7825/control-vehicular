<?php

namespace Database\Factories;

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\TipoViaje;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class ViajeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'solicitante_id' => Usuario::factory(),
            'tipo' => TipoViaje::Inmediato,
            'modo' => ModoViaje::MasCercano,
            'obligatorio' => false,
            'origen_lat' => -34.6037, 'origen_lng' => -58.3816,
            'destino_lat' => -34.6090, 'destino_lng' => -58.3920,
            'motivo' => 'Traslado',
            'estado' => EstadoViaje::Buscando,
        ];
    }
}
