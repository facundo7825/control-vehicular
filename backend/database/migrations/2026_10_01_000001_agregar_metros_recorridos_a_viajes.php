<?php

use App\Servicios\KilometrosRecorridos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los metros recorridos de cada viaje se guardan al finalizarlo (MaquinaEstadosViaje) en lugar de recorrer
 * los puntos GPS en cada consulta del mapa o de los reportes. Índices para las consultas por fecha.
 *
 * Relleno de los viajes ya finalizados con el recorrido que quede: los que no tienen ningún punto (se borraron
 * por la retención) quedan en null, "sin dato"; los reportes avisan cuando el rango incluye alguno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viajes', function (Blueprint $t) {
            $t->unsignedInteger('metros_recorridos')->nullable();
            $t->index('created_at');
            $t->index('finalizado_en');
            $t->index(['chofer_id', 'finalizado_en']);
        });

        DB::table('viajes')
            ->where('estado', 'finalizado')
            ->whereNull('metros_recorridos')
            ->select('id')
            ->chunkById(500, function ($viajes) {
                $ids = $viajes->pluck('id')->all();
                $metros = KilometrosRecorridos::metrosPorViaje($ids);
                $conPuntos = DB::table('recorrido_viaje')->whereIn('viaje_id', $ids)->distinct()->pluck('viaje_id')
                    ->map(fn ($id) => (int) $id)->all();

                foreach ($conPuntos as $id) {
                    DB::table('viajes')->where('id', $id)
                        ->update(['metros_recorridos' => (int) round($metros[$id] ?? 0)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('viajes', function (Blueprint $t) {
            $t->dropIndex(['created_at']);
            $t->dropIndex(['finalizado_en']);
            $t->dropIndex(['chofer_id', 'finalizado_en']);
            $t->dropColumn('metros_recorridos');
        });
    }
};
