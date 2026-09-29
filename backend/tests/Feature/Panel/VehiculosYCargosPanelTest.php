<?php

use App\Filament\Resources\CargosPrioritarios\Pages\CreateCargoPrioritario;
use App\Filament\Resources\CargosPrioritarios\Pages\ListCargosPrioritarios;
use App\Filament\Resources\Vehiculos\Pages\CreateVehiculo;
use App\Filament\Resources\Vehiculos\Pages\EditVehiculo;
use App\Filament\Resources\Vehiculos\Pages\ListVehiculos;
use App\Models\CargoPrioritario;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(Usuario::factory()->admin()->create()));

it('lista los vehículos y filtra los activos', function () {
    $activo = Vehiculo::factory()->create();
    $inactivo = Vehiculo::factory()->create(['activo' => false]);

    Livewire::test(ListVehiculos::class)
        ->assertCanSeeTableRecords([$activo, $inactivo])
        ->filterTable('activo', true)
        ->assertCanSeeTableRecords([$activo])
        ->assertCanNotSeeTableRecords([$inactivo]);
});

it('crea un vehículo', function () {
    Livewire::test(CreateVehiculo::class)
        ->fillForm(['patente' => 'AB123CD', 'marca' => 'Toyota', 'modelo' => 'Etios', 'color' => 'Gris'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Vehiculo::sole())->patente->toBe('AB123CD')->activo->toBeTrue();
});

it('no acepta una patente repetida', function () {
    Vehiculo::factory()->create(['patente' => 'AB123CD']);

    Livewire::test(CreateVehiculo::class)
        ->fillForm(['patente' => 'AB123CD', 'marca' => 'Toyota', 'modelo' => 'Etios'])
        ->call('create')
        ->assertHasFormErrors(['patente' => 'unique']);
});

it('desactiva un vehículo desde la edición', function () {
    $vehiculo = Vehiculo::factory()->create();

    Livewire::test(EditVehiculo::class, ['record' => $vehiculo->getRouteKey()])
        ->fillForm(['activo' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($vehiculo->fresh()->activo)->toBeFalse();
});

it('solo deja borrar vehículos sin historial', function () {
    $usado = Turno::factory()->create()->vehiculo;
    $nuevo = Vehiculo::factory()->create();

    Livewire::test(EditVehiculo::class, ['record' => $usado->getRouteKey()])
        ->assertActionHidden('delete');

    Livewire::test(EditVehiculo::class, ['record' => $nuevo->getRouteKey()])
        ->callAction('delete');

    expect(Vehiculo::pluck('id')->all())->toBe([$usado->id]);
});

it('administra los cargos prioritarios', function () {
    Livewire::test(CreateCargoPrioritario::class)
        ->fillForm(['cargo' => 'Juez', 'obligatorio' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateCargoPrioritario::class)
        ->fillForm(['cargo' => 'Juez'])
        ->call('create')
        ->assertHasFormErrors(['cargo' => 'unique']);

    expect(CargoPrioritario::esObligatorio('Juez'))->toBeTrue();

    Livewire::test(ListCargosPrioritarios::class)
        ->callAction(TestAction::make('delete')->table(CargoPrioritario::sole()));

    expect(CargoPrioritario::count())->toBe(0);
});
