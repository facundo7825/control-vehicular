<?php

use App\Filament\Resources\Dependencias\Pages\CreateDependencia;
use App\Filament\Resources\Dependencias\Pages\EditDependencia;
use App\Filament\Resources\Dependencias\Pages\ListDependencias;
use App\Models\Dependencia;
use App\Models\Usuario;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(Usuario::factory()->admin()->create()));

it('crea una dependencia con los choferes que la atienden', function () {
    [$uno, $otro] = Usuario::factory()->chofer()->count(2)->create();

    Livewire::test(CreateDependencia::class)
        ->fillForm(['nombre' => 'Fuero Penal', 'choferes' => [$uno->id, $otro->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    $dependencia = Dependencia::sole();
    expect($dependencia->activa)->toBeTrue()
        ->and($dependencia->choferes->pluck('id')->sort()->values()->all())->toBe([$uno->id, $otro->id]);
});

it('no acepta un nombre repetido', function () {
    Dependencia::create(['nombre' => 'Fuero Penal']);

    Livewire::test(CreateDependencia::class)
        ->fillForm(['nombre' => 'Fuero Penal'])
        ->call('create')
        ->assertHasFormErrors(['nombre' => 'unique']);
});

it('solo ofrece choferes activos', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $inactivo = Usuario::factory()->chofer()->create(['activo' => false]);
    $solicitante = Usuario::factory()->create();

    $opciones = Livewire::test(CreateDependencia::class)
        ->instance()->form->getComponent('choferes')->getOptions();

    expect($opciones)->toHaveKey($chofer->id)
        ->not->toHaveKey($inactivo->id)
        ->not->toHaveKey($solicitante->id);
});

it('edita los choferes y desactiva la dependencia', function () {
    $dependencia = Dependencia::create(['nombre' => 'Civil']);
    [$sale, $entra] = Usuario::factory()->chofer()->count(2)->create();
    $dependencia->choferes()->attach($sale);

    Livewire::test(EditDependencia::class, ['record' => $dependencia->getRouteKey()])
        ->assertFormSet(['choferes' => [$sale->id]])
        ->fillForm(['choferes' => [$entra->id], 'activa' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $dependencia->refresh();
    expect($dependencia->activa)->toBeFalse()
        ->and($dependencia->choferes->pluck('id')->all())->toBe([$entra->id]);
});

it('lista la cantidad de choferes y de personas', function () {
    $dependencia = Dependencia::create(['nombre' => 'Civil']);
    $dependencia->choferes()->attach(Usuario::factory()->chofer()->count(2)->create());
    Usuario::factory()->count(3)->create(['dependencia_id' => $dependencia->id]);

    Livewire::test(ListDependencias::class)
        ->assertCanSeeTableRecords([$dependencia])
        ->assertTableColumnStateSet('choferes_count', 2, $dependencia)
        ->assertTableColumnStateSet('personas_count', 3, $dependencia);
});

it('al borrar una dependencia sus personas quedan sin dependencia', function () {
    $dependencia = Dependencia::create(['nombre' => 'Civil']);
    $persona = Usuario::factory()->create(['dependencia_id' => $dependencia->id]);
    $chofer = Usuario::factory()->chofer()->create();
    $dependencia->choferes()->attach($chofer);

    Livewire::test(EditDependencia::class, ['record' => $dependencia->getRouteKey()])->callAction('delete');

    expect(Dependencia::count())->toBe(0)
        ->and($persona->fresh()->dependencia_id)->toBeNull()
        ->and($chofer->dependenciasQueAtiende()->count())->toBe(0);
});

it('el nombre se guarda sin espacios de más', function () {
    expect(Dependencia::create(['nombre' => '  Fuero   Penal '])->nombre)->toBe('Fuero Penal');
});
