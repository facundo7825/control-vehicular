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
     * El chofer está libre para [inicio, inicio + duración] si ninguna de sus reservas o viajes largos tomados
     * (aceptados o ya en marcha) se superpone con esa franja más el colchón. Uno que ya salió y se pasó de su
     * duración estimada lo sigue ocupando hasta ahora. No exige turno abierto; con un turno en cierre pendiente
     * (fichó la salida durante un viaje) no toma reservas que empiecen antes del fin estimado de su viaje activo
     * más el colchón, pero sí las posteriores.
     *
     * Con $bloquear, las reservas se leen con FOR UPDATE: dentro de una transacción de asignación
     * en MySQL/MariaDB (REPEATABLE READ), una lectura común podría devolver una foto anterior al bloqueo.
     */
    public function estaDisponible(int $choferId, Carbon $inicio, int $duracionMin, ?int $excluirViajeId = null, bool $bloquear = false): bool
    {
        $colchon = $this->parametros->entero('colchon_reservas_min');
        $porDefecto = $this->parametros->entero('duracion_reserva_por_defecto_min');

        // Fichó la salida durante un viaje (cierre pendiente): no toma nada que empiece antes de que lo termine.
        if (Turno::where('chofer_id', $choferId)->whereNull('fin')->whereNotNull('cierre_pendiente_en')->exists()
            && $inicio->lt($this->finEstimadoViajeActivo($choferId, $porDefecto)->addMinutes($colchon))) {
            return false;
        }

        return Viaje::where('chofer_id', $choferId)
            ->whereIn('tipo', TipoViaje::agendados())
            ->whereIn('estado', EstadoViaje::conChofer())
            ->when($excluirViajeId, fn ($q) => $q->whereKeyNot($excluirViajeId))
            // Con el índice forzado, FOR UPDATE bloquea solo filas de este chofer. Sin él, en tablas chicas
            // MySQL recorre toda la tabla y choca con el viaje que otra aceptación ya bloqueó (deadlock).
            ->when($bloquear, fn ($q) => $q->forceIndex('viajes_chofer_id_estado_index')->lockForUpdate())
            ->get()
            ->doesntContain(fn (Viaje $r) => self::seSuperponen(
                $inicio, $duracionMin, $r->programado_para, self::minutosOcupados($r, $porDefecto), $colchon,
            ));
    }

    /**
     * Minutos de la franja de un viaje agendado desde su hora programada: la duración estimada o, si ya salió
     * (en camino, llegó o en curso) y se pasó de ella, hasta ahora.
     */
    private static function minutosOcupados(Viaje $v, int $porDefecto): int
    {
        $minutos = $v->duracion_estimada_min ?? $porDefecto;

        if (in_array($v->estado, [EstadoViaje::EnCamino, EstadoViaje::Llego, EstadoViaje::EnCurso], true)
            && $v->programado_para->copy()->addMinutes($minutos)->lt(now())) {
            $minutos = (int) ceil($v->programado_para->diffInSeconds(now()) / 60);
        }

        return $minutos;
    }

    /**
     * Cuándo se estima que el chofer termina lo que tiene activo ahora (nunca antes de ahora): desde la hora
     * programada (o desde que lo aceptó, un viaje inmediato), la duración estimada o la de por defecto.
     */
    private function finEstimadoViajeActivo(int $choferId, int $porDefecto): Carbon
    {
        return Viaje::activosDeChofer($choferId)->get()
            ->map(fn (Viaje $v) => ($v->programado_para ?? $v->aceptado_en ?? now())->copy()
                ->addMinutes($v->duracion_estimada_min ?? $porDefecto))
            ->push(now())
            ->max();
    }

    /**
     * El vehículo está libre para [inicio, inicio + duración] si no está en otro viaje largo tomado (aceptado o
     * en marcha, hasta ahora si se pasó de su regreso estimado) que se superponga con esa franja más el colchón.
     * Las reservas toman el vehículo del turno al salir, así que no cuentan.
     *
     * Con $bloquear, igual que estaDisponible: lectura con FOR UPDATE, solo sobre las filas de ese vehículo.
     */
    public function vehiculoDisponible(int $vehiculoId, Carbon $inicio, int $duracionMin, ?int $excluirViajeId = null, bool $bloquear = false): bool
    {
        $colchon = $this->parametros->entero('colchon_reservas_min');
        $porDefecto = $this->parametros->entero('duracion_reserva_por_defecto_min');

        return Viaje::where('vehiculo_id', $vehiculoId)
            ->where('tipo', TipoViaje::Largo)
            ->whereIn('estado', EstadoViaje::conChofer())
            ->when($excluirViajeId, fn ($q) => $q->whereKeyNot($excluirViajeId))
            ->when($bloquear, fn ($q) => $q->forceIndex('viajes_vehiculo_id_estado_index')->lockForUpdate())
            ->get()
            ->doesntContain(fn (Viaje $v) => self::seSuperponen(
                $inicio, $duracionMin, $v->programado_para, self::minutosOcupados($v, $porDefecto), $colchon,
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
