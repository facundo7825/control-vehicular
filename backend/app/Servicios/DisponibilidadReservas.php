<?php

namespace App\Servicios;

use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Mapas\ServicioMapas;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Franjas ocupadas por reservas y choferes disponibles para una nueva (spec 4.2). */
class DisponibilidadReservas
{
    public function __construct(
        private Parametros $parametros,
        private ServicioMapas $mapas,
    ) {}

    /** Minutos estimados del viaje: ruta de Google más el margen, o el valor por defecto sin dato. */
    public function duracionEstimada(float $oLat, float $oLng, float $dLat, float $dLng): int
    {
        $segundos = $this->mapas->duracionRuta($oLat, $oLng, $dLat, $dLng);

        if ($segundos === null) {
            return $this->parametros->entero('duracion_reserva_por_defecto_min');
        }

        return (int) ceil($segundos / 60) + $this->parametros->entero('margen_duracion_reserva_min');
    }

    /**
     * ¿La franja B toca la franja A extendida con el colchón antes y después?
     * Que coincidan justo en el borde del colchón no cuenta como superposición.
     */
    public static function seSuperponen(Carbon $inicioA, int $minutosA, Carbon $inicioB, int $minutosB, int $colchonMin): bool
    {
        $desde = $inicioA->copy()->subMinutes($colchonMin);
        $hasta = $inicioA->copy()->addMinutes($minutosA + $colchonMin);

        return $inicioB->lt($hasta) && $inicioB->copy()->addMinutes($minutosB)->gt($desde);
    }

    /**
     * El chofer está libre para [inicio, inicio + duración] si ninguna de sus reservas tomadas
     * (aceptadas o ya en marcha) se superpone con esa franja más el colchón. No exige turno abierto,
     * pero con un turno en cierre pendiente (fichó la salida durante un viaje) no toma reservas nuevas.
     *
     * Con $bloquear, las reservas se leen con FOR UPDATE: dentro de una transacción de asignación
     * en MySQL/MariaDB (REPEATABLE READ), una lectura común podría devolver una foto anterior al bloqueo.
     */
    public function estaDisponible(int $choferId, Carbon $inicio, int $duracionMin, ?int $excluirViajeId = null, bool $bloquear = false): bool
    {
        // Fichó la salida durante un viaje (cierre pendiente): no toma reservas nuevas.
        if (Turno::where('chofer_id', $choferId)->whereNull('fin')->whereNotNull('cierre_pendiente_en')->exists()) {
            return false;
        }

        $colchon = $this->parametros->entero('colchon_reservas_min');
        $porDefecto = $this->parametros->entero('duracion_reserva_por_defecto_min');

        return Viaje::where('chofer_id', $choferId)
            ->where('tipo', TipoViaje::Reserva)
            ->whereIn('estado', EstadoViaje::conChofer())
            ->when($excluirViajeId, fn ($q) => $q->whereKeyNot($excluirViajeId))
            // Con el índice forzado, FOR UPDATE bloquea solo filas de este chofer. Sin él, en tablas chicas
            // MySQL recorre toda la tabla y choca con el viaje que otra aceptación ya bloqueó (deadlock).
            ->when($bloquear, fn ($q) => $q->forceIndex('viajes_chofer_id_estado_index')->lockForUpdate())
            ->get()
            ->doesntContain(fn (Viaje $r) => self::seSuperponen(
                $inicio, $duracionMin, $r->programado_para, $r->duracion_estimada_min ?? $porDefecto, $colchon,
            ));
    }

    /** @return Collection<int, array{chofer: Usuario, reservas_del_dia: int}> */
    public function choferesDisponibles(Carbon $inicio, int $duracionMin): Collection
    {
        return Usuario::where('rol', RolUsuario::Chofer)
            ->where('activo', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (Usuario $c) => $this->estaDisponible($c->id, $inicio, $duracionMin))
            ->map(fn (Usuario $c) => ['chofer' => $c, 'reservas_del_dia' => $this->reservasDelDia($c->id, $inicio)])
            ->sort(fn (array $a, array $b) => [$a['reservas_del_dia'], $a['chofer']->id] <=> [$b['reservas_del_dia'], $b['chofer']->id])
            ->values();
    }

    /** Reservas tomadas o ya hechas por el chofer en el mismo día calendario (hora de los usuarios) que $momento. */
    public function reservasDelDia(int $choferId, Carbon $momento): int
    {
        $desde = $momento->copy()
            ->setTimezone(config('vehiculos.zona_horaria'))
            ->startOfDay()
            ->setTimezone(config('app.timezone'));

        return Viaje::where('chofer_id', $choferId)
            ->where('tipo', TipoViaje::Reserva)
            ->whereIn('estado', [...EstadoViaje::conChofer(), EstadoViaje::Finalizado])
            ->where('programado_para', '>=', $desde)
            ->where('programado_para', '<', $desde->copy()->addDay())
            ->count();
    }
}
