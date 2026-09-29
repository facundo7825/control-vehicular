<?php

use App\Models\Usuario;

it('guarda el token push del usuario', function () {
    $u = Usuario::factory()->create();

    $this->actingAs($u)->postJson('/api/push/token', ['token' => 'fcm-abc'])->assertNoContent();

    expect($u->fresh()->token_push)->toBe('fcm-abc');
});
