<?php

use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Console\Scheduling\Schedule;

function intercambiar(string $idExterno): string
{
    return test()->postJson('/api/auth/intercambio', ['token_externo' => "sim|$idExterno|Ana|Empleado"])->json('token');
}

it('el token vale antes de 24 h y da 401 pasadas las 24 h', function () {
    $token = intercambiar('1');

    $this->travel(24 * 60 - 1)->minutes();
    $this->withToken($token)->getJson('/api/yo')->assertOk();

    $this->travel(2)->minutes();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/yo')->assertUnauthorized();
});

it('un token vencido también da 401 en la autorización de canales', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'clave',
        'broadcasting.connections.reverb.secret' => 'secreto',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    require base_path('routes/channels.php');

    $viaje = Viaje::factory()->create();
    $token = $viaje->solicitante->createToken('app')->plainTextToken;
    $datos = ['socket_id' => '1234.5678', 'channel_name' => "private-viaje.{$viaje->id}"];

    $this->withToken($token)->postJson('/api/broadcasting/auth', $datos)->assertOk();

    $this->travel(24 * 60 + 1)->minutes();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/broadcasting/auth', $datos)->assertUnauthorized();
});

it('tras 7 intercambios quedan 5 tokens: el más nuevo funciona y los 2 más viejos no', function () {
    $tokens = collect(range(1, 7))->map(fn () => intercambiar('10'))->all();

    expect(Usuario::firstWhere('id_externo', '10')->tokens()->count())->toBe(5);

    foreach ([0, 1] as $i) {
        $this->app['auth']->forgetGuards();
        $this->withToken($tokens[$i])->getJson('/api/yo')->assertUnauthorized();
    }
    foreach ([2, 6] as $i) {
        $this->app['auth']->forgetGuards();
        $this->withToken($tokens[$i])->getJson('/api/yo')->assertOk();
    }
});

it('el tope de tokens no toca los de otros usuarios', function () {
    $otro = Usuario::factory()->create();
    $otro->createToken('app');
    $otro->createToken('app');

    foreach (range(1, 7) as $_) {
        intercambiar('11');
    }

    expect($otro->tokens()->count())->toBe(2);
});

it('recortarTokens conserva los más recientes', function () {
    $u = Usuario::factory()->create();
    foreach (range(1, 4) as $_) {
        $u->createToken('app');
    }

    $u->recortarTokens(2);

    expect($u->tokens()->orderBy('id')->pluck('id')->all())->toBe(
        $u->tokens()->orderByDesc('id')->limit(2)->pluck('id')->sort()->values()->all()
    )->and($u->tokens()->count())->toBe(2);
});

it('programa la poda diaria de tokens vencidos', function () {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'sanctum:prune-expired'));

    expect($evento?->expression)->toBe('30 3 * * *')
        ->and($evento->command)->toContain('--hours=24');
});
