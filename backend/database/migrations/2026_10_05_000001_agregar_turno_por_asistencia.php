<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turno automático por fichaje de asistencia: el vehículo habitual con el que se abre el turno, el cierre
 * pendiente (fichó la salida con un viaje activo) y el registro de los eventos que manda el sistema del PJ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->foreignId('vehiculo_habitual_id')->nullable()->constrained('vehiculos')->nullOnDelete();
        });

        Schema::table('turnos', function (Blueprint $t) {
            $t->timestamp('cierre_pendiente_en')->nullable();
        });

        // Sin timestamps NOT NULL (ver nota en la migración inicial): momento lo completa siempre ServicioAsistencia.
        Schema::create('eventos_asistencia', function (Blueprint $t) {
            $t->id();
            $t->string('id_evento')->nullable()->unique();
            $t->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $t->string('id_externo');
            $t->string('tipo', 10);
            $t->timestamp('momento')->nullable();
            $t->string('resultado', 20);
            $t->string('motivo');
            $t->timestamps();
            $t->index(['id_externo', 'momento']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_asistencia');

        Schema::table('turnos', function (Blueprint $t) {
            $t->dropColumn('cierre_pendiente_en');
        });

        Schema::table('usuarios', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vehiculo_habitual_id');
        });
    }
};
