<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Viajes largos (al interior o a otra provincia), asignados por el encargado: regreso estimado y pasajeros.
 * El índice por vehículo permite bloquear (FOR UPDATE) solo los viajes de ese vehículo al validar que no esté
 * en dos viajes largos que se superpongan. Los horarios laborales son parámetros (config + tabla parametros).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viajes', function (Blueprint $t) {
            $t->timestamp('regreso_estimado')->nullable();
            $t->text('pasajeros')->nullable();
            $t->index(['vehiculo_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('viajes', function (Blueprint $t) {
            $t->dropIndex(['vehiculo_id', 'estado']);
            $t->dropColumn(['regreso_estimado', 'pasajeros']);
        });
    }
};
