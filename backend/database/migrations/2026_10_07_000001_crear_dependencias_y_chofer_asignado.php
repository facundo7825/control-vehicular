<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asignación por persona y por dependencia: la dependencia (fuero u oficina) de cada solicitante, el chofer
 * asignado a una persona y las dependencias que atiende cada chofer. Lo carga el encargado desde el panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependencias', function (Blueprint $t) {
            $t->id();
            $t->string('nombre')->unique();
            $t->boolean('activa')->default(true);
            $t->timestamps();
        });

        Schema::table('usuarios', function (Blueprint $t) {
            $t->foreignId('dependencia_id')->nullable()->constrained('dependencias')->nullOnDelete();
            $t->foreignId('chofer_asignado_id')->nullable()->constrained('usuarios')->nullOnDelete();
        });

        Schema::create('chofer_dependencia', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chofer_id')->constrained('usuarios')->cascadeOnDelete();
            $t->foreignId('dependencia_id')->constrained('dependencias')->cascadeOnDelete();
            $t->unique(['chofer_id', 'dependencia_id']);
            $t->index('dependencia_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chofer_dependencia');

        Schema::table('usuarios', function (Blueprint $t) {
            $t->dropConstrainedForeignId('chofer_asignado_id');
            $t->dropConstrainedForeignId('dependencia_id');
        });

        Schema::dropIfExists('dependencias');
    }
};
