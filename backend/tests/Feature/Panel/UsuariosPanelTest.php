<?php

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\OrigenTurno;
use App\Enums\RolUsuario;
use App\Filament\Resources\Usuarios\Pages\EditUsuario;
use App\Filament\Resources\Usuarios\Pages\ListUsuarios;
use App\Filament\Resources\Usuarios\RelationManagers\TurnosRelationManager;
use App\Models\EventoAsistencia;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Tests\Fakes\NotificadorFalso;

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

it('asigna el vehículo habitual de un chofer, eligiendo entre los activos', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $vehiculo = Vehiculo::factory()->create(['patente' => 'AB123CD', 'marca' => 'Toyota', 'modelo' => 'Corolla']);
    $inactivo = Vehiculo::factory()->create(['activo' => false]);

    $pagina = Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])
        ->assertFormFieldVisible('vehiculo_habitual_id');

    $opciones = $pagina->instance()->form->getComponent('vehiculo_habitual_id')->getOptions();
    expect($opciones)->toHaveKey($vehiculo->id)
        ->and($opciones[$vehiculo->id])->toBe('AB123CD — Toyota Corolla')
        ->and($opciones)->not->toHaveKey($inactivo->id);

    $pagina->fillForm(['vehiculo_habitual_id' => $vehiculo->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($chofer->fresh()->vehiculo_habitual_id)->toBe($vehiculo->id);
});

it('el vehículo habitual solo se muestra para los choferes', function () {
    $solicitante = Usuario::factory()->create();

    Livewire::test(EditUsuario::class, ['record' => $solicitante->getRouteKey()])
        ->assertFormFieldHidden('vehiculo_habitual_id')
        ->fillForm(['rol' => RolUsuario::Chofer->value])
        ->assertFormFieldVisible('vehiculo_habitual_id');
});

it('muestra el origen y el cierre pendiente de los turnos', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $manual = Turno::factory()->for($chofer, 'chofer')->create(['inicio' => now()->subDay(), 'fin' => now()->subDay()->addHours(8)]);
    $asistencia = Turno::factory()->for($chofer, 'chofer')->create(['origen' => OrigenTurno::Asistencia, 'cierre_pendiente_en' => now()]);

    Livewire::test(TurnosRelationManager::class, ['ownerRecord' => $chofer, 'pageClass' => EditUsuario::class])
        ->assertTableColumnFormattedStateSet('origen', 'Manual', $manual)
        ->assertTableColumnFormattedStateSet('origen', 'Asistencia', $asistencia)
        ->assertTableColumnFormattedStateSet('cierre_pendiente_en', 'Cierre pendiente', $asistencia)
        ->assertTableColumnFormattedStateNotSet('cierre_pendiente_en', 'Cierre pendiente', $manual);
});

it('simula la entrada de un chofer y abre el turno con su vehículo habitual', function () {
    $this->app->instance(Notificador::class, new NotificadorFalso);
    $vehiculo = Vehiculo::factory()->create();
    $chofer = Usuario::factory()->chofer()->create(['vehiculo_habitual_id' => $vehiculo->id]);

    Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])
        ->callAction('simularFichaje', data: ['tipo' => EventoAsistencia::ENTRADA])
        ->assertHasNoActionErrors()
        ->assertNotified('Fichaje de entrada: turno abierto')
        ->assertDispatched(TurnosRelationManager::EVENTO_ACTUALIZAR);

    $turno = $chofer->turnoAbierto()->first();
    expect($turno->vehiculo_id)->toBe($vehiculo->id)
        ->and($turno->origen)->toBe(OrigenTurno::Asistencia)
        ->and(EventoAsistencia::where('usuario_id', $chofer->id)->value('resultado'))->toBe(EventoAsistencia::ABIERTO);
});

it('simula la salida de un chofer y cierra el turno', function () {
    $this->app->instance(Notificador::class, new NotificadorFalso);
    $chofer = Turno::factory()->create()->chofer;

    Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])
        ->callAction('simularFichaje', data: ['tipo' => EventoAsistencia::SALIDA])
        ->assertNotified('Fichaje de salida: turno cerrado');

    expect($chofer->turnoAbierto()->exists())->toBeFalse();
});

it('simular la entrada sin vehículo habitual avisa el motivo', function () {
    $this->app->instance(Notificador::class, new NotificadorFalso);
    $chofer = Usuario::factory()->chofer()->create();

    Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])
        ->callAction('simularFichaje', data: ['tipo' => EventoAsistencia::ENTRADA])
        ->assertNotified(
            Notification::make()
                ->warning()
                ->title('Fichaje de entrada: sin vehículo')
                ->body('No tiene vehículo habitual asignado.')
        );

    expect($chofer->turnoAbierto()->exists())->toBeFalse();
});

it('simular fichaje solo está para los choferes', function () {
    Livewire::test(EditUsuario::class, ['record' => Usuario::factory()->create()->getRouteKey()])
        ->assertActionHidden('simularFichaje');
});

it('simular una segunda entrada avisa que se ignoró', function () {
    $this->app->instance(Notificador::class, new NotificadorFalso);
    $chofer = Turno::factory()->create()->chofer;

    Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])
        ->callAction('simularFichaje', data: ['tipo' => EventoAsistencia::ENTRADA])
        ->assertNotified(
            Notification::make()
                ->info()
                ->title('Fichaje de entrada: ignorado')
                ->body('Ya tenía un turno abierto.')
        );

    expect(Turno::where('chofer_id', $chofer->id)->count())->toBe(1);
});

it('simular fichaje no está para un chofer sin id_externo', function () {
    $chofer = Usuario::factory()->chofer()->create(['id_externo' => '']); // la columna no admite null: "sin id" es vacío

    Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])
        ->assertActionHidden('simularFichaje');
});

it('simular fichaje solo está si la simulación está habilitada', function () {
    $chofer = Usuario::factory()->chofer()->create();

    config(['vehiculos.asistencia.simulacion' => true]);
    Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])->assertActionVisible('simularFichaje');

    config(['vehiculos.asistencia.simulacion' => false]);
    Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])->assertActionHidden('simularFichaje');
});

it('sin ASISTENCIA_SIMULACION, simular fichaje está fuera de producción y no en producción', function () {
    $chofer = Usuario::factory()->chofer()->create();
    config(['vehiculos.asistencia.simulacion' => null]);

    Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])->assertActionVisible('simularFichaje');

    $this->app['env'] = 'production';
    Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])->assertActionHidden('simularFichaje');
});

it('ofrece el vehículo habitual actual aunque se haya desactivado', function () {
    $inactivo = Vehiculo::factory()->create(['activo' => false, 'patente' => 'ZZ999ZZ', 'marca' => 'Ford', 'modelo' => 'Ka']);
    $otroInactivo = Vehiculo::factory()->create(['activo' => false]);
    $chofer = Usuario::factory()->chofer()->create(['vehiculo_habitual_id' => $inactivo->id]);

    $opciones = Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])
        ->instance()->form->getComponent('vehiculo_habitual_id')->getOptions();

    expect($opciones)->toHaveKey($inactivo->id)
        ->and($opciones[$inactivo->id])->toBe('ZZ999ZZ — Ford Ka')
        ->and($opciones)->not->toHaveKey($otroInactivo->id);
});
