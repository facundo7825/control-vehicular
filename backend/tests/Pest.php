<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** Crea un chofer con turno abierto y ubicación reportada hace $minutos minutos. */
function choferEnTurno(float $lat = -34.60, float $lng = -58.38, int $minutos = 0): App\Models\Usuario
{
    $turno = App\Models\Turno::factory()->create();
    App\Models\UbicacionChofer::create([
        'chofer_id' => $turno->chofer_id, 'lat' => $lat, 'lng' => $lng,
        'actualizado_en' => now()->subMinutes($minutos),
    ]);

    return $turno->chofer;
}

// Carreras entre procesos contra MySQL/MariaDB; se corren aparte: ./vendor/bin/pest tests/Concurrencia
pest()->extend(Tests\TestCase::class)->in('Concurrencia');
