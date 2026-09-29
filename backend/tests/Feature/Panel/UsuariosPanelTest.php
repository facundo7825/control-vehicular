<?php

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Filament\Resources\Usuarios\Pages\EditUsuario;
use App\Filament\Resources\Usuarios\Pages\ListUsuarios;
use App\Filament\Resources\Usuarios\RelationManagers\TurnosRelationManager;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs($this->admin = Usuario::factory()->admin()->create()));

it('filtra por rol y por activo', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $inactivo = Usuario::factory()->create(['activo' => false]);

    Livewire::test(ListUsuarios::class)
        ->assertCanSeeTableRecords([$this->admin, $chofer, $inactivo])
        ->filterTable('rol', RolUsuario::Chofer)
        ->assertCanSeeTableRecords([$chofer])
        ->assertCanNotSeeTableRecords([$this->admin, $inactivo])
        ->resetTableFilters()
        ->filterTable('activo', false)
        ->assertCanSeeTableRecords([$inactivo])
        ->assertCanNotSeeTableRecords([$this->admin, $chofer]);
});

it('muestra el estado calculado solo para los choferes', function () {
    $libre = choferEnTurno();
    $fuera = Usuario::factory()->chofer()->create();
    $solicitante = Usuario::factory()->create();

    Livewire::test(ListUsuarios::class)
        ->assertTableColumnStateSet('estado_chofer', EstadoChofer::Libre, $libre)
        ->assertTableColumnStateSet('estado_chofer', EstadoChofer::FueraDeTurno, $fuera)
        ->assertTableColumnStateSet('estado_chofer', null, $solicitante);
});

it('le da el rol de chofer a un solicitante sin tocar sus datos del PJ', function () {
    $usuario = Usuario::factory()->create(['nombre' => 'Juan Pérez', 'cargo' => 'Ujier']);

    Livewire::test(EditUsuario::class, ['record' => $usuario->getRouteKey()])
        ->assertFormFieldDisabled('nombre')
        ->assertFormFieldDisabled('cargo')
        ->assertFormFieldDisabled('id_externo')
        ->fillForm(['rol' => RolUsuario::Chofer->value, 'nombre' => 'Otro'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($usuario->fresh())
        ->rol->toBe(RolUsuario::Chofer)
        ->nombre->toBe('Juan Pérez');
});

it('un admin no puede quitarse el rol ni desactivarse', function () {
    Livewire::test(EditUsuario::class, ['record' => $this->admin->getRouteKey()])
        ->assertFormFieldDisabled('rol')
        ->assertFormFieldDisabled('activo')
        ->fillForm(['rol' => RolUsuario::Solicitante->value, 'activo' => false])
        ->call('save');

    expect($this->admin->fresh())
        ->rol->toBe(RolUsuario::Admin)
        ->activo->toBeTrue();
});

it('no deja quitarle el rol a un chofer con turno abierto o viajes asignados', function () {
    $enTurno = choferEnTurno();
    $conReserva = Usuario::factory()->chofer()->create();
    reservaAceptada($conReserva, now()->addDay());

    foreach ([$enTurno, $conReserva] as $chofer) {
        Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])
            ->fillForm(['activo' => false])
            ->call('save')
            ->assertNotified('El chofer tiene un turno abierto o viajes asignados.');

        expect($chofer->fresh()->activo)->toBeTrue();
    }
});

it('desactiva a un chofer sin trabajo pendiente', function () {
    $chofer = Usuario::factory()->chofer()->create();
    Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Finalizado]);

    Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])
        ->fillForm(['activo' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($chofer->fresh()->activo)->toBeFalse();
});

it('muestra los turnos del chofer', function () {
    $turno = Turno::factory()->create(['fin' => now()]);

    Livewire::test(TurnosRelationManager::class, [
        'ownerRecord' => $turno->chofer,
        'pageClass' => EditUsuario::class,
    ])->assertCanSeeTableRecords([$turno]);

    expect(TurnosRelationManager::canViewForRecord($turno->chofer, EditUsuario::class))->toBeTrue()
        ->and(TurnosRelationManager::canViewForRecord(Usuario::factory()->create(), EditUsuario::class))->toBeFalse();
});
