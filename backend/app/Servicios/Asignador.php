<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Mapas\Distancia;
use App\Mapas\ServicioMapas;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Asignador
{
    public function __construct(
        private ServicioMapas $mapas,
        private Parametros $parametros,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
    ) {}

    /**
     * @param  Collection<int, Usuario>  $candidatos  con la relación `ubicacion` cargada
     * @return Collection<int, Usuario>
     */
    public function ordenarPorCercania(Viaje $viaje, Collection $candidatos): Collection
    {
        $porDistancia = $candidatos
            ->filter(fn (Usuario $c) => $c->ubicacion !== null)
            ->sortBy(fn (Usuario $c) => Distancia::metros(
                $c->ubicacion->lat, $c->ubicacion->lng, $viaje->origen_lat, $viaje->origen_lng,
            ))
            ->values();

        $n = $this->parametros->entero('candidatos_distance_matrix');
        $primeros = $porDistancia->take($n);

        $duraciones = $this->mapas->duracionesHacia(
            $primeros->mapWithKeys(fn (Usuario $c) => [$c->id => [$c->ubicacion->lat, $c->ubicacion->lng]])->all(),
            $viaje->origen_lat,
            $viaje->origen_lng,
        );

        // sortBy es estable: sin datos de Google se conserva el orden por distancia.
        return $primeros
            ->sortBy(fn (Usuario $c) => $duraciones[$c->id] ?? PHP_INT_MAX)
            ->concat($porDistancia->slice($n))
            ->values();
    }

    public function asignar(Viaje $viaje, Usuario $chofer): bool
    {
        $asignado = DB::transaction(function () use ($viaje, $chofer) {
            $bloqueado = Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail();
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();

            if (! in_array($bloqueado->estado, [EstadoViaje::Buscando, EstadoViaje::Ofrecido], true)) {
                return false;
            }
            if ($this->estados->estado($chofer) !== EstadoChofer::Libre) {
                return false;
            }

            return $this->maquina->transicionar($bloqueado, EstadoViaje::Aceptado, [
                'chofer_id' => $chofer->id,
                'vehiculo_id' => $chofer->turnoAbierto()->value('vehiculo_id'),
            ]);
        });

        $viaje->refresh();

        return $asignado;
    }
}
