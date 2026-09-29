<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id();
            $t->string('id_externo')->unique();
            $t->string('nombre');
            $t->string('cargo')->nullable();
            $t->string('rol')->default('solicitante');
            $t->string('telefono')->nullable();
            $t->string('token_push')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        Schema::create('cargos_prioritarios', function (Blueprint $t) {
            $t->id();
            $t->string('cargo')->unique();
            $t->boolean('obligatorio')->default(false);
            $t->timestamps();
        });

        Schema::create('vehiculos', function (Blueprint $t) {
            $t->id();
            $t->string('patente')->unique();
            $t->string('marca');
            $t->string('modelo');
            $t->string('color')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        Schema::create('turnos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chofer_id')->constrained('usuarios');
            $t->foreignId('vehiculo_id')->constrained('vehiculos');
            $t->timestamp('inicio');
            $t->timestamp('fin')->nullable();
            $t->string('origen')->default('manual');
            $t->timestamps();
            $t->index(['chofer_id', 'fin']);
            $t->index(['vehiculo_id', 'fin']);
        });

        Schema::create('ubicaciones_chofer', function (Blueprint $t) {
            $t->foreignId('chofer_id')->primary()->constrained('usuarios');
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->float('rumbo')->nullable();
            $t->float('velocidad')->nullable();
            $t->timestamp('actualizado_en');
        });

        Schema::create('viajes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('solicitante_id')->constrained('usuarios');
            $t->foreignId('chofer_id')->nullable()->constrained('usuarios');
            $t->foreignId('vehiculo_id')->nullable()->constrained('vehiculos');
            $t->string('tipo');
            $t->string('modo');
            $t->boolean('obligatorio')->default(false);
            $t->decimal('origen_lat', 10, 7);
            $t->decimal('origen_lng', 10, 7);
            $t->string('origen_direccion')->nullable();
            $t->decimal('destino_lat', 10, 7);
            $t->decimal('destino_lng', 10, 7);
            $t->string('destino_direccion')->nullable();
            $t->string('motivo')->nullable();
            $t->timestamp('programado_para')->nullable();
            $t->unsignedInteger('duracion_estimada_min')->nullable();
            $t->string('estado');
            $t->timestamp('aceptado_en')->nullable();
            $t->timestamp('llego_en')->nullable();
            $t->timestamp('iniciado_en')->nullable();
            $t->timestamp('finalizado_en')->nullable();
            $t->timestamp('cancelado_en')->nullable();
            $t->string('cancelado_por')->nullable();
            $t->string('motivo_cancelacion')->nullable();
            $t->timestamps();
            $t->index(['chofer_id', 'estado']);
            $t->index(['solicitante_id', 'estado']);
        });

        Schema::create('ofertas_viaje', function (Blueprint $t) {
            $t->id();
            $t->foreignId('viaje_id')->constrained('viajes');
            $t->foreignId('chofer_id')->constrained('usuarios');
            $t->string('resultado')->default('pendiente');
            $t->timestamp('ofrecido_en');
            $t->timestamp('vence_en');
            $t->timestamp('respondido_en')->nullable();
            $t->string('motivo')->nullable(); // motivo si el chofer canceló tras aceptar
            $t->timestamps();
            $t->index(['chofer_id', 'resultado']);
        });

        Schema::create('recorrido_viaje', function (Blueprint $t) {
            $t->id();
            $t->foreignId('viaje_id')->constrained('viajes');
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->timestamp('registrado_en');
        });

        Schema::create('parametros', function (Blueprint $t) {
            $t->string('clave')->primary();
            $t->string('valor');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['parametros', 'recorrido_viaje', 'ofertas_viaje', 'viajes', 'ubicaciones_chofer',
                  'turnos', 'vehiculos', 'cargos_prioritarios', 'usuarios'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
