<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sin timestamps NOT NULL: resuelta_en y los de timestamps() son nullable (ver nota en la migración inicial).
        Schema::create('alertas', function (Blueprint $t) {
            $t->id();
            $t->string('tipo');
            $t->foreignId('viaje_id')->nullable()->constrained('viajes');
            $t->foreignId('chofer_id')->nullable()->constrained('usuarios');
            $t->string('mensaje');
            $t->timestamp('resuelta_en')->nullable();
            $t->timestamps();
            $t->index(['resuelta_en', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
    }
};
