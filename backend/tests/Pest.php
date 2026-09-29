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

/** Crea una reserva ya aceptada por $chofer (sin pasar por el despachador). */
function reservaAceptada(App\Models\Usuario $chofer, DateTimeInterface $inicio, int $duracion = 60, array $attrs = []): App\Models\Viaje
{
    return App\Models\Viaje::factory()->create([
        'tipo' => App\Enums\TipoViaje::Reserva,
        'modo' => App\Enums\ModoViaje::Especifico,
        'chofer_id' => $chofer->id,
        'estado' => App\Enums\EstadoViaje::Aceptado,
        'aceptado_en' => now(),
        'programado_para' => $inicio,
        'duracion_estimada_min' => $duracion,
        ...$attrs,
    ]);
}

/** Crea una reserva recién pedida (estado buscando), por defecto para mañana a las 15:00 UTC, de 60 minutos. */
function reservaBuscando(array $attrs = []): App\Models\Viaje
{
    return App\Models\Viaje::factory()->create([
        'tipo' => App\Enums\TipoViaje::Reserva,
        'modo' => App\Enums\ModoViaje::Especifico,
        'programado_para' => now()->addDay()->setTime(15, 0),
        'duracion_estimada_min' => 60,
        ...$attrs,
    ]);
}

/** Reemplaza ServicioMapas por uno cuya duración de ruta es siempre $segundos (null = Google sin dato). */
function fijarDuracionRuta(?int $segundos): void
{
    app()->instance(App\Mapas\ServicioMapas::class, new class($segundos) implements App\Mapas\ServicioMapas {
        public function __construct(private ?int $segundos) {}

        public function duracionesHacia(array $origenes, float $lat, float $lng): array
        {
            return array_map(fn () => null, $origenes);
        }

        public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
        {
            return $this->segundos;
        }
    });
}

// Carreras entre procesos contra MySQL/MariaDB; se corren aparte: ./vendor/bin/pest tests/Concurrencia
pest()->extend(Tests\TestCase::class)->in('Concurrencia');
