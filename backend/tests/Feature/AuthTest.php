<?php

use App\Identidad\DatosIdentidad;
use App\Identidad\IdentidadNoDisponible;
use App\Identidad\ProveedorIdentidad;
use App\Models\Dependencia;
use App\Models\Usuario;

it('intercambia un token válido por un token propio y crea el usuario', function () {
    $r = $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|123|Ana Pérez|Juez']);

    $r->assertOk()
        ->assertJsonPath('usuario.nombre', 'Ana Pérez')
        ->assertJsonPath('usuario.cargo', 'Juez')
        ->assertJsonPath('usuario.rol', 'solicitante');
    expect($r->json('token'))->toBeString()->not->toBeEmpty()
        ->and(Usuario::where('id_externo', '123')->count())->toBe(1);
});

it('actualiza el cargo en logins posteriores sin duplicar el usuario', function () {
    $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|123|Ana Pérez|Secretaria']);
    $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|123|Ana Pérez|Juez'])->assertOk();

    expect(Usuario::where('id_externo', '123')->count())->toBe(1)
        ->and(Usuario::firstWhere('id_externo', '123')->cargo)->toBe('Juez');
});

it('conserva el rol asignado localmente', function () {
    Usuario::factory()->chofer()->create(['id_externo' => '77']);

    $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|77|Juan|Chofer'])
        ->assertJsonPath('usuario.rol', 'chofer');
});

it('rechaza un token inválido con 401', function () {
    $this->postJson('/api/auth/intercambio', ['token_externo' => 'cualquier-cosa'])->assertUnauthorized();
});

it('rechaza a un usuario desactivado con 403', function () {
    Usuario::factory()->create(['id_externo' => '9', 'activo' => false]);

    $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|9|X|Empleado'])->assertForbidden();
});

it('responde 503 si el servicio de identidad no está disponible', function () {
    $this->app->instance(ProveedorIdentidad::class, new class implements ProveedorIdentidad
    {
        public function validar(string $tokenExterno): ?DatosIdentidad
        {
            throw new IdentidadNoDisponible;
        }
    });

    $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|1|A|B'])->assertStatus(503);
});

it('devuelve el usuario autenticado en /yo', function () {
    $token = $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|5|Luis|Empleado'])->json('token');

    $this->withToken($token)->getJson('/api/yo')->assertOk()->assertJsonPath('nombre', 'Luis');
});

it('un usuario desactivado pierde el acceso a la API aunque tenga token', function () {
    $token = $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|6|Eva|Empleado'])->json('token');
    $usuario = Usuario::firstWhere('id_externo', '6');

    $usuario->update(['activo' => false]);

    expect($usuario->tokens()->count())->toBe(0);
    $this->withToken($token)->getJson('/api/yo')->assertUnauthorized();
});

it('rechaza con 403 a un usuario inactivo que conserva la sesión', function () {
    $usuario = Usuario::factory()->create(['activo' => false]);

    $this->actingAs($usuario)->getJson('/api/yo')->assertForbidden();
});

function identidadConDependencia(?string $dependencia): void
{
    app()->instance(ProveedorIdentidad::class, new class($dependencia) implements ProveedorIdentidad
    {
        public function __construct(private ?string $dependencia) {}

        public function validar(string $tokenExterno): ?DatosIdentidad
        {
            return new DatosIdentidad('321', 'Ana Pérez', 'Juez', dependencia: $this->dependencia);
        }
    });
}

it('fija la dependencia que informa el PJ, creándola si no existe', function () {
    identidadConDependencia('Fuero  Penal');

    $this->postJson('/api/auth/intercambio', ['token_externo' => 'x'])->assertOk();

    $dependencia = Dependencia::sole();
    expect($dependencia->nombre)->toBe('Fuero Penal')
        ->and($dependencia->activa)->toBeTrue()
        ->and(Usuario::firstWhere('id_externo', '321')->dependencia_id)->toBe($dependencia->id);
});

it('busca la dependencia sin distinguir mayúsculas ni espacios de más', function () {
    $existente = Dependencia::create(['nombre' => 'Fuero Penal']);
    identidadConDependencia('  fuero   PENAL ');

    $this->postJson('/api/auth/intercambio', ['token_externo' => 'x'])->assertOk();

    expect(Dependencia::count())->toBe(1)
        ->and(Usuario::firstWhere('id_externo', '321')->dependencia_id)->toBe($existente->id);
});

it('si el PJ no informa la dependencia, conserva la cargada en el panel', function () {
    $dependencia = Dependencia::create(['nombre' => 'Mesa de Entradas']);
    Usuario::factory()->create(['id_externo' => '321', 'dependencia_id' => $dependencia->id]);
    identidadConDependencia(null);

    $this->postJson('/api/auth/intercambio', ['token_externo' => 'x'])->assertOk();

    expect(Usuario::firstWhere('id_externo', '321')->dependencia_id)->toBe($dependencia->id);
});
