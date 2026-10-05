<?php

use App\Filament\Resources\EventosAsistencia\EventoAsistenciaResource;
use App\Filament\Resources\EventosAsistencia\Pages\ListEventosAsistencia;
use App\Models\EventoAsistencia;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-05 15:00:00'));
    $this->actingAs(Usuario::factory()->admin()->create());
});

function fichaje(array $attrs = []): EventoAsistencia
{
    return EventoAsistencia::create($attrs + [
        'id_externo' => 'L1',
        'tipo' => EventoAsistencia::ENTRADA,
        'momento' => now(),
        'resultado' => EventoAsistencia::ABIERTO,
        'motivo' => 'Turno abierto con el vehículo habitual.',
    ]);
}

it('lista los fichajes, los más nuevos primero, con la persona, el resultado y la hora local', function () {
    $chofer = Usuario::factory()->chofer()->create(['nombre' => 'Carlos Chofer', 'id_externo' => 'L1']);
    $viejo = fichaje(['usuario_id' => $chofer->id, 'momento' => now()->subHour()]);
    $nuevo = fichaje(['id_externo' => 'X9', 'tipo' => EventoAsistencia::SALIDA, 'resultado' => EventoAsistencia::IGNORADO, 'motivo' => 'No hay ningún usuario con ese id_externo.']);

    Livewire::test(ListEventosAsistencia::class)
        ->assertCanSeeTableRecords([$nuevo, $viejo], inOrder: true)
        ->assertTableColumnStateSet('persona', 'Carlos Chofer', $viejo)
        ->assertTableColumnStateSet('persona', 'X9 (sin usuario)', $nuevo)
        ->assertTableColumnFormattedStateSet('resultado', 'Ignorado', $nuevo)
        ->assertTableColumnFormattedStateSet('tipo', 'Salida', $nuevo)
        // 15:00 UTC = 12:00 en Argentina.
        ->assertTableColumnFormattedStateSet('momento', '05/10 12:00', $nuevo)
        ->assertSee('No hay ningún usuario con ese id_externo.');

    expect(EventoAsistenciaResource::canCreate())->toBeFalse();
});

it('filtra los fichajes por resultado y por fecha', function () {
    $abierto = fichaje();
    $sinVehiculo = fichaje(['resultado' => EventoAsistencia::SIN_VEHICULO, 'motivo' => 'No tiene vehículo habitual asignado.']);
    $ayer = fichaje(['momento' => now()->subDay()]);

    Livewire::test(ListEventosAsistencia::class)
        ->filterTable('resultado', EventoAsistencia::SIN_VEHICULO)
        ->assertCanSeeTableRecords([$sinVehiculo])
        ->assertCanNotSeeTableRecords([$abierto, $ayer])
        ->resetTableFilters()
        ->filterTable('fecha', ['desde' => '2026-10-05', 'hasta' => '2026-10-05'])
        ->assertCanSeeTableRecords([$abierto, $sinVehiculo])
        ->assertCanNotSeeTableRecords([$ayer]);
});
