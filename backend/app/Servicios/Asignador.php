<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
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
        private DisponibilidadReservas $disponibilidad,
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

    /**
     * Asigna una reserva a futuro (spec 5.4). No exige que el chofer esté libre ahora ni en turno:
     * solo que la franja siga libre en su agenda. El vehículo se toma del turno al salir (en_camino).
     */
    public function asignarReserva(Viaje $viaje, Usuario $chofer): bool
    {
        $asignado = DB::transaction(function () use ($viaje, $chofer) {
            $bloqueado = Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail();
            // Mismo bloqueo que asignar(): dos aceptaciones del mismo chofer se serializan acá.
            $c = Usuario::whereKey($chofer->id)->lockForUpdate()->first();

            if ($bloqueado->tipo !== TipoViaje::Reserva
                || ! in_array($bloqueado->estado, [EstadoViaje::Buscando, EstadoViaje::Ofrecido], true)
                || ! $c?->esChofer()
                || ! $c->activo) {
                return false;
            }

            $libre = $this->disponibilidad->estaDisponible(
                $c->id,
                $bloqueado->programado_para,
                $bloqueado->duracion_estimada_min ?? $this->parametros->entero('duracion_reserva_por_defecto_min'),
                excluirViajeId: $bloqueado->id,
                bloquear: true,
            );
            if (! $libre) {
                return false;
            }

            return $this->maquina->transicionar($bloqueado, EstadoViaje::Aceptado, ['chofer_id' => $c->id]);
        });

        $viaje->refresh();

        return $asignado;
    }
}
