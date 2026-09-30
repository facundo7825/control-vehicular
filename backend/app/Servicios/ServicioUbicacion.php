<?php

namespace App\Servicios;

use App\Enums\EstadoViaje;
use App\Excepciones\ReglaNegocio;
use App\Models\PuntoRecorrido;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Support\HoraLocal;

class ServicioUbicacion
{
    /** @param array<int, array{lat: float, lng: float, rumbo?: ?float, velocidad?: ?float, registrado_en: string}> $puntos */
    public function registrar(Usuario $chofer, array $puntos): void
    {
        if (! $chofer->turnoAbierto()->exists()) {
            throw new ReglaNegocio('Iniciá un turno para compartir tu ubicación.');
        }

        $puntos = collect($puntos)
            ->map(fn (array $p) => [...$p, 'momento' => HoraLocal::interpretar($p['registrado_en'])->min(now())])
            ->sortBy('momento')
            ->values();
        $ultimo = $puntos->last();

        $actual = UbicacionChofer::find($chofer->id);
        if (! $actual || $actual->actualizado_en->lte($ultimo['momento'])) {
            UbicacionChofer::updateOrCreate(['chofer_id' => $chofer->id], [
                'lat' => $ultimo['lat'],
                'lng' => $ultimo['lng'],
                'rumbo' => $ultimo['rumbo'] ?? null,
                'velocidad' => $ultimo['velocidad'] ?? null,
                'actualizado_en' => $ultimo['momento'],
            ]);
            \App\Events\UbicacionChoferActualizada::dispatch(
                $chofer->id,
                (float) $ultimo['lat'],
                (float) $ultimo['lng'],
                isset($ultimo['rumbo']) ? (float) $ultimo['rumbo'] : null,
                $ultimo['momento']->toIso8601String(),
                Viaje::activosDeChofer($chofer->id)->value('id'),
            );
        }

        $enCurso = Viaje::where('chofer_id', $chofer->id)->where('estado', EstadoViaje::EnCurso)->first();
        if ($enCurso) {
            // Idempotente: un lote reenviado (la app no recibió el 204) no duplica puntos. El índice único
            // (viaje_id, registrado_en) descarta los que ya estaban, también dentro del mismo lote.
            $filas = $puntos->filter(fn ($p) => $p['momento']->gte($enCurso->iniciado_en))
                ->map(fn ($p) => [
                    'viaje_id' => $enCurso->id, 'lat' => $p['lat'], 'lng' => $p['lng'], 'registrado_en' => $p['momento'],
                ])
                ->values()
                ->all();
            if ($filas !== []) {
                PuntoRecorrido::insertOrIgnore($filas);
            }
        }
    }
}
