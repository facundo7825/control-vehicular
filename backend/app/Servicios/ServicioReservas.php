<?php

namespace App\Servicios;

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Excepciones\ReglaNegocio;
use App\Models\CargoPrioritario;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Support\HoraLocal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Reservas a futuro (spec 5.4): franja, choferes disponibles y creación. */
class ServicioReservas
{
    public function __construct(
        private DisponibilidadReservas $disponibilidad,
        private Asignador $asignador,
        private Despachador $despachador,
        private Parametros $parametros,
        private CompletadorDirecciones $direcciones,
    ) {}

    /**
     * Valida la anticipación y estima la duración.
     *
     * @return array{0: Carbon, 1: int} inicio (en la zona de la app) y duración estimada en minutos
     */
    public function franja(array $datos): array
    {
        // Eloquent guarda el Carbon sin convertirlo: se pasa a la zona de la app (y una hora sin offset es local).
        $inicio = HoraLocal::interpretar($datos['programado_para']);

        $anticipacion = $this->parametros->entero('anticipacion_minima_reserva_min');
        if ($inicio->lt(now()->addMinutes($anticipacion))) {
            throw new ReglaNegocio("La reserva debe hacerse con al menos $anticipacion minutos de anticipación.");
        }

        $duracion = $this->disponibilidad->duracionEstimada(
            (float) $datos['origen_lat'], (float) $datos['origen_lng'],
            (float) $datos['destino_lat'], (float) $datos['destino_lng'],
        );

        return [$inicio, $duracion];
    }

    /** @return array{duracion_estimada_min: int, choferes: array<int, array{id: int, nombre: string, reservas_del_dia: int}>} */
    public function disponibles(array $datos): array
    {
        [$inicio, $duracion] = $this->franja($datos);

        return [
            'duracion_estimada_min' => $duracion,
            'choferes' => $this->disponibilidad->choferesDisponibles($inicio, $duracion)
                ->map(fn (array $f) => [
                    'id' => $f['chofer']->id,
                    'nombre' => $f['chofer']->nombre,
                    'reservas_del_dia' => $f['reservas_del_dia'],
                ])
                ->values()
                ->all(),
        ];
    }

    public function crear(Usuario $solicitante, array $datos): Viaje
    {
        [$inicio, $duracion] = $this->franja($datos);
        $modo = ModoViaje::from($datos['modo']);
        $candidatos = $this->candidatos($modo, isset($datos['chofer_id']) ? (int) $datos['chofer_id'] : null, $inicio, $duracion);
        $obligatorio = CargoPrioritario::esObligatorio($solicitante->cargo);

        // Antes de la transacción: la consulta de las direcciones que faltan no retiene ningún lock.
        $datos = $this->direcciones->completar($datos);

        // Una sola transacción: si entre la consulta y la asignación otra reserva ganó la franja
        // de todos los candidatos, se revierte y no queda ningún viaje creado.
        $viaje = DB::transaction(function () use ($solicitante, $datos, $modo, $inicio, $duracion, $obligatorio, $candidatos) {
            $viaje = Viaje::create([
                'solicitante_id' => $solicitante->id,
                'tipo' => TipoViaje::Reserva,
                'modo' => $modo,
                'obligatorio' => $obligatorio,
                'origen_lat' => $datos['origen_lat'],
                'origen_lng' => $datos['origen_lng'],
                'origen_direccion' => $datos['origen_direccion'] ?? null,
                'destino_lat' => $datos['destino_lat'],
                'destino_lng' => $datos['destino_lng'],
                'destino_direccion' => $datos['destino_direccion'] ?? null,
                'motivo' => $datos['motivo'] ?? null,
                'programado_para' => $inicio,
                'duracion_estimada_min' => $duracion,
                'estado' => EstadoViaje::Buscando,
            ]);

            foreach ($candidatos as $chofer) {
                $listo = $obligatorio
                    ? $this->asignador->asignarReserva($viaje, $chofer)
                    : $this->despachador->ofrecerReserva($viaje, $chofer);

                if ($listo) {
                    return $viaje;
                }
            }

            throw new ReglaNegocio($modo === ModoViaje::Especifico
                ? 'El chofer elegido no está disponible en ese horario.'
                : 'No hay choferes disponibles en ese horario.');
        }, attempts: 3);

        $this->direcciones->reintentarSiFalta($viaje);

        return $viaje->refresh()->load(['chofer', 'vehiculo', 'solicitante']);
    }

    /** @return Collection<int, Usuario> */
    private function candidatos(ModoViaje $modo, ?int $choferId, Carbon $inicio, int $duracion): Collection
    {
        if ($modo === ModoViaje::Especifico) {
            $chofer = Usuario::where('rol', RolUsuario::Chofer)->where('activo', true)->find($choferId)
                ?? throw new ReglaNegocio('El chofer elegido no existe.');
            if (! $this->disponibilidad->estaDisponible($chofer->id, $inicio, $duracion)) {
                throw new ReglaNegocio('El chofer elegido no está disponible en ese horario.');
            }

            return collect([$chofer]);
        }

        $choferes = $this->disponibilidad->choferesDisponibles($inicio, $duracion)->pluck('chofer');
        if ($choferes->isEmpty()) {
            throw new ReglaNegocio('No hay choferes disponibles en ese horario.');
        }

        return $choferes;
    }
}
