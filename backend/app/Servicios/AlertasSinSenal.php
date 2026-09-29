<?php

namespace App\Servicios;

use App\Models\Alerta;
use App\Models\Viaje;
use App\Support\HoraLocal;

/**
 * Spec 9: si un chofer con un viaje activo lleva no_disponible_min sin enviar su ubicación, se alerta al panel.
 * Una sola alerta pendiente por viaje y chofer; se resuelve sola cuando vuelve la señal o el viaje deja de estar activo.
 */
class AlertasSinSenal
{
    public function __construct(private Parametros $parametros) {}

    /** @return array{creadas: int, resueltas: int} */
    public function revisar(): array
    {
        $limite = now()->subMinutes($this->parametros->entero('no_disponible_min'));

        $sinSenal = Viaje::activos()
            ->whereHas('chofer.turnoAbierto')
            ->with('chofer.ubicacion')
            ->get()
            ->filter(fn (Viaje $v) => ! $v->chofer->ubicacion || $v->chofer->ubicacion->actualizado_en->lt($limite))
            ->keyBy(fn (Viaje $v) => "{$v->id}:{$v->chofer_id}");

        $pendientes = Alerta::pendientes()
            ->where('tipo', Alerta::CHOFER_SIN_SENAL)
            ->get()
            ->keyBy(fn (Alerta $a) => "{$a->viaje_id}:{$a->chofer_id}");

        $resueltas = $pendientes->diffKeys($sinSenal);
        Alerta::whereKey($resueltas->pluck('id'))->update(['resuelta_en' => now()]);

        // Si el admin ya resolvió la alerta de este mismo corte de señal, no se la vuelve a crear:
        // solo cuenta como corte nuevo si el chofer reportó ubicación después de esa alerta.
        $nuevas = $sinSenal->diffKeys($pendientes)->reject(fn (Viaje $v) => Alerta::where('tipo', Alerta::CHOFER_SIN_SENAL)
            ->where('viaje_id', $v->id)
            ->where('chofer_id', $v->chofer_id)
            ->where('created_at', '>=', $v->chofer->ubicacion?->actualizado_en ?? $v->chofer->turnoAbierto->inicio)
            ->exists());
        foreach ($nuevas as $viaje) {
            $ubicacion = $viaje->chofer->ubicacion;
            $desde = $ubicacion
                ? 'desde las '.HoraLocal::formatear($ubicacion->actualizado_en, 'H:i')
                : 'desde que inició el turno';

            Alerta::create([
                'tipo' => Alerta::CHOFER_SIN_SENAL,
                'viaje_id' => $viaje->id,
                'chofer_id' => $viaje->chofer_id,
                'mensaje' => "{$viaje->chofer->nombre} no envía su ubicación $desde y tiene el viaje #{$viaje->id} activo.",
            ]);
        }

        return ['creadas' => $nuevas->count(), 'resueltas' => $resueltas->count()];
    }
}
