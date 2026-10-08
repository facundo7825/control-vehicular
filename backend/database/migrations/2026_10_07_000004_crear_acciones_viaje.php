<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acciones del chofer sobre un viaje ("Voy en camino", "Llegué", "Iniciar", "Finalizar") enviadas con su hora
 * real. `id_accion` (uuid de la app) hace idempotente el reenvío: una acción ya aplicada no se vuelve a aplicar.
 * `momento` es cuándo la tocó el chofer y `aplicada_en` cuándo llegó al servidor (el panel marca las enviadas
 * sin señal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acciones_viaje', function (Blueprint $t) {
            $t->id();
            $t->string('id_accion', 64)->nullable()->unique();
            $t->foreignId('viaje_id')->constrained('viajes');
            // Sin clave foránea: el insert no toma un lock más sobre el chofer (el orden de locks no cambia).
            $t->foreignId('chofer_id')->index();
            $t->string('estado', 20);
            $t->timestamp('momento')->useCurrent();
            $t->timestamp('aplicada_en')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acciones_viaje');
    }
};
