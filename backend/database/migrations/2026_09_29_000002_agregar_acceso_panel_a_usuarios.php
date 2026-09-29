<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Solo los administradores del panel tienen email y contraseña; el resto entra con el token del PJ.
        Schema::table('usuarios', function (Blueprint $t) {
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->rememberToken();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->dropUnique(['email']);
            $t->dropColumn(['email', 'password', 'remember_token']);
        });
    }
};
