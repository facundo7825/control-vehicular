<?php

use App\Models\CargoPrioritario;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    fijarDuracionRuta(1500); // 25 min + 15 de margen = 40
});

function datosReserva(array $extra = []): array
{
    return [
        'programado_para' => '2026-10-02T12:00:00-03:00', // 15:00 UTC
        'modo' => 'cualquiera_disponible',
        'origen_lat' => -34.600, 'origen_lng' => -58.380, 'origen_direccion' => 'Talcahuano 550',
        'destino_lat' => -34.609, 'destino_lng' => -58.392, 'destino_direccion' => 'Tribunales',
        'motivo' => 'Audiencia',
        ...$extra,
    ];
}

it('crea una reserva para un chofer específico y se la ofrece', function () {
    $chofer = Usuario::factory()->chofer()->create();

    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/reservas', datosReserva(['modo' => 'especifico', 'chofer_id' => $chofer->id]))
        ->assertCreated()
        ->assertJsonPath('tipo', 'reserva')
        ->assertJsonPath('modo', 'especifico')
        ->assertJsonPath('estado', 'ofrecido')
        ->assertJsonPath('programado_para', '2026-10-02T15:00:00+00:00')
        ->assertJsonPath('duracion_estimada_min', 40)
        ->assertJsonPath('destino.direccion', 'Tribunales');

    expect(OfertaViaje::sole()->chofer_id)->toBe($chofer->id);
});

it('asigna directo una reserva obligatoria, sin vehículo hasta que empiece', function () {
    CargoPrioritario::create(['cargo' => 'Juez', 'obligatorio' => true]);
    $chofer = Usuario::factory()->chofer()->create();

    $this->actingAs(Usuario::factory()->create(['cargo' => 'Juez']))
        ->postJson('/api/reservas', datosReserva(['modo' => 'especifico', 'chofer_id' => $chofer->id]))
        ->assertCreated()
        ->assertJsonPath('estado', 'aceptado')
        ->assertJsonPath('obligatorio', true)
        ->assertJsonPath('chofer.id', $chofer->id)
        ->assertJsonPath('vehiculo', null);

    expect(OfertaViaje::count())->toBe(0);
});

it('con cualquiera disponible elige al chofer con menos reservas ese día', function () {
    $cargado = Usuario::factory()->chofer()->create();
    reservaAceptada($cargado, Carbon::parse('2026-10-02 19:00'));
    $liviano = Usuario::factory()->chofer()->create();

    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/reservas', datosReserva())
        ->assertCreated()
        ->assertJsonPath('modo', 'cualquiera_disponible')
        ->assertJsonPath('estado', 'ofrecido');

    expect(OfertaViaje::sole()->chofer_id)->toBe($liviano->id);
});

it('rechaza un chofer ocupado en esa franja y no crea nada', function () {
    $chofer = Usuario::factory()->chofer()->create();
    reservaAceptada($chofer, Carbon::parse('2026-10-02 15:30'));

    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/reservas', datosReserva(['modo' => 'especifico', 'chofer_id' => $chofer->id]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'El chofer elegido no está disponible en ese horario.');

    expect(Viaje::count())->toBe(1);
});

it('rechaza si nadie está disponible y no crea nada', function () {
    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/reservas', datosReserva())
        ->assertStatus(422)
        ->assertJsonPath('message', 'No hay choferes disponibles en ese horario.');

    expect(Viaje::count())->toBe(0);
});

it('exige la anticipación mínima', function () {
    Usuario::factory()->chofer()->create();

    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/reservas', datosReserva(['programado_para' => now()->addMinutes(59)->toIso8601String()]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'La reserva debe hacerse con al menos 60 minutos de anticipación.');

    expect(Viaje::count())->toBe(0);
});

it('valida el modo y el chofer', function () {
    $solicitante = Usuario::factory()->create();

    $this->actingAs($solicitante)->postJson('/api/reservas', datosReserva(['modo' => 'mas_cercano']))
        ->assertJsonValidationErrors('modo');
    $this->actingAs($solicitante)->postJson('/api/reservas', datosReserva(['modo' => 'especifico']))
        ->assertJsonValidationErrors('chofer_id');
});

it('un chofer no puede reservar', function () {
    $this->actingAs(Usuario::factory()->chofer()->create())
        ->postJson('/api/reservas', datosReserva())
        ->assertForbidden();
});

it('lista los choferes disponibles con la duración estimada', function () {
    reservaAceptada(Usuario::factory()->chofer()->create(), Carbon::parse('2026-10-02 15:30'));
    $libre = Usuario::factory()->chofer()->create(['nombre' => 'Ana Chofer']);

    $this->actingAs(Usuario::factory()->create())
        ->getJson('/api/reservas/disponibles?'.http_build_query(datosReserva()))
        ->assertOk()
        ->assertExactJson([
            'duracion_estimada_min' => 40,
            'choferes' => [['id' => $libre->id, 'nombre' => 'Ana Chofer', 'reservas_del_dia' => 0]],
        ]);
});

it('sin dato de Google estima la duración por defecto', function () {
    fijarDuracionRuta(null);

    $this->actingAs(Usuario::factory()->create())
        ->getJson('/api/reservas/disponibles?'.http_build_query(datosReserva()))
        ->assertOk()
        ->assertJsonPath('duracion_estimada_min', 60)
        ->assertJsonPath('choferes', []);
});

it('interpreta una hora sin offset como hora de Buenos Aires, no UTC', function () {
    Usuario::factory()->chofer()->create();

    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/reservas', datosReserva(['programado_para' => '2026-10-02T12:00:00']))
        ->assertCreated()
        ->assertJsonPath('programado_para', '2026-10-02T15:00:00+00:00');
});
