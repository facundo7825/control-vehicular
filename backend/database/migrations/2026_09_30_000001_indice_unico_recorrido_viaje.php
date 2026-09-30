<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un punto del recorrido por viaje y momento: si la app reenvía un lote porque no le llegó el 204,
 * los puntos repetidos se ignoran en vez de duplicarse.
 * `registrado_en` no se toca (sigue NOT NULL con useCurrent()).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Los repetidos que ya existan (de reenvíos anteriores) impedirían crear el índice: se deja el primero.
        $repetidos = DB::table('recorrido_viaje')
            ->select('viaje_id', 'registrado_en', DB::raw('MIN(id) as conservar'))
            ->groupBy('viaje_id', 'registrado_en')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($repetidos as $r) {
            DB::table('recorrido_viaje')
                ->where('viaje_id', $r->viaje_id)
                ->where('registrado_en', $r->registrado_en)
                ->where('id', '!=', $r->conservar)
                ->delete();
        }

        Schema::table('recorrido_viaje', function (Blueprint $t) {
            $t->unique(['viaje_id', 'registrado_en']);
        });
    }

    public function down(): void
    {
        Schema::table('recorrido_viaje', function (Blueprint $t) {
            $t->dropUnique(['viaje_id', 'registrado_en']);
        });
    }
};
