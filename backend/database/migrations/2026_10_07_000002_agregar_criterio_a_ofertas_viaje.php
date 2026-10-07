<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué criterio se ofreció el viaje (chofer asignado, dependencia o cercanía). Va aparte de `motivo`,
 * que guarda por qué canceló el chofer. Nulo en las ofertas a un chofer elegido por el solicitante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ofertas_viaje', function (Blueprint $t) {
            $t->string('criterio')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ofertas_viaje', function (Blueprint $t) {
            $t->dropColumn('criterio');
        });
    }
};
