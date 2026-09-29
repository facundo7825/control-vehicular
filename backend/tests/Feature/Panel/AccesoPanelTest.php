<?php

use App\Enums\RolUsuario;
use App\Models\Usuario;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('manda al login a quien no inició sesión', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('deja entrar a un admin activo y muestra el panel en español', function () {
    $this->actingAs(Usuario::factory()->admin()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Escritorio');

    expect(app()->getLocale())->toBe('es');
});

it('no cambia el idioma de la API', function () {
    $this->actingAs(Usuario::factory()->create())->getJson('/api/yo')->assertOk();

    expect(app()->getLocale())->toBe(config('app.locale'));
});

it('rechaza con 403 a solicitantes, choferes y admins inactivos', function (array $atributos) {
    $this->actingAs(Usuario::factory()->create($atributos))->get('/admin')->assertForbidden();
})->with([
    'solicitante' => [['rol' => RolUsuario::Solicitante]],
    'chofer' => [['rol' => RolUsuario::Chofer]],
    'admin inactivo' => [['rol' => RolUsuario::Admin, 'activo' => false]],
]);

it('inicia sesión con email y contraseña', function () {
    $admin = Usuario::factory()->admin()->create(['email' => 'admin@pj.gob.ar', 'password' => 'secreta123']);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@pj.gob.ar', 'password' => 'secreta123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($admin);
});

it('no inicia sesión a un chofer aunque tenga contraseña', function () {
    Usuario::factory()->chofer()->create(['email' => 'chofer@pj.gob.ar', 'password' => 'secreta123']);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'chofer@pj.gob.ar', 'password' => 'secreta123'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('no expone la contraseña al serializar el usuario', function () {
    $admin = Usuario::factory()->admin()->create(['email' => 'a@pj.gob.ar', 'password' => 'secreta123']);

    expect($admin->toArray())->not->toHaveKey('password')
        ->and(Hash::check('secreta123', $admin->fresh()->password))->toBeTrue();
});

it('crea un admin nuevo desde la consola', function () {
    $this->artisan('vehiculos:crear-admin', ['email' => 'Nuevo@PJ.gob.ar', '--nombre' => 'Ana Admin', '--password' => 'secreta123'])
        ->assertSuccessful();

    $admin = Usuario::where('email', 'nuevo@pj.gob.ar')->sole();
    expect($admin->rol)->toBe(RolUsuario::Admin)
        ->and($admin->nombre)->toBe('Ana Admin')
        ->and($admin->id_externo)->toBe('panel:nuevo@pj.gob.ar')
        ->and(Hash::check('secreta123', $admin->password))->toBeTrue();
});

it('promueve a admin a un usuario que ya entró por la app', function () {
    $usuario = Usuario::factory()->create(['id_externo' => '4242', 'activo' => false]);

    $this->artisan('vehiculos:crear-admin', ['email' => 'jefe@pj.gob.ar', '--id-externo' => '4242', '--password' => 'secreta123'])
        ->assertSuccessful();

    expect($usuario->fresh())
        ->rol->toBe(RolUsuario::Admin)
        ->activo->toBeTrue()
        ->email->toBe('jefe@pj.gob.ar')
        ->and(Usuario::count())->toBe(1);
});

it('pide la contraseña por consola si no se pasa como opción', function () {
    $this->artisan('vehiculos:crear-admin', ['email' => 'a@pj.gob.ar'])
        ->expectsQuestion('Contraseña', 'secreta123')
        ->expectsQuestion('Repetí la contraseña', 'secreta123')
        ->assertSuccessful();

    expect(Hash::check('secreta123', Usuario::where('email', 'a@pj.gob.ar')->value('password')))->toBeTrue();
});

it('rechaza contraseñas cortas, emails usados por otro y ids externos inexistentes', function (array $argumentos) {
    Usuario::factory()->create(['email' => 'ocupado@pj.gob.ar']);
    Usuario::factory()->create(['id_externo' => '777']);

    $this->artisan('vehiculos:crear-admin', $argumentos)->assertFailed();

    expect(Usuario::where('rol', RolUsuario::Admin)->exists())->toBeFalse();
})->with([
    'contraseña corta' => [['email' => 'a@pj.gob.ar', '--password' => 'corta']],
    'email de otro' => [['email' => 'ocupado@pj.gob.ar', '--id-externo' => '777', '--password' => 'secreta123']],
    'id externo inexistente' => [['email' => 'b@pj.gob.ar', '--id-externo' => 'no-existe', '--password' => 'secreta123']],
]);

it('no promueve a admin a un chofer con turno abierto', function () {
    $chofer = choferEnTurno();

    $this->artisan('vehiculos:crear-admin', ['email' => 'jefe@pj.gob.ar', '--id-externo' => $chofer->id_externo, '--password' => 'secreta123'])
        ->assertFailed();

    expect($chofer->fresh()->rol)->toBe(RolUsuario::Chofer);
});
