<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** El estado del chofer se calcula siempre; nunca se guarda (spec 4.1). */
class CalculadorEstadoChofer
{
    public function __construct(private Parametros $parametros) {}

    public function estado(Usuario $chofer): EstadoChofer
    {
        $turno = $chofer->turnoAbierto()->first();
        if (! $turno) {
            return EstadoChofer::FueraDeTurno;
        }

        return $this->segun(
            $turno->cierre_pendiente_en !== null,
            $chofer->ubicacion()->first(),
            fn () => Viaje::activosDeChofer($chofer->id)->exists(),
            fn () => $this->reservasProximas()->where('chofer_id', $chofer->id)->exists(),
            $this->limiteSenal(),
        );
    }

    /**
     * Mismo criterio que estado(), con consultas agrupadas: una cantidad fija de consultas sin importar
     * cuántos choferes haya en turno (lo usan el mapa, el tablero y el despachador).
     *
     * @return Collection<int, array{chofer: Usuario, estado: EstadoChofer}>
     */
    public function choferesEnTurno(): Collection
    {
        $choferes = Usuario::where('rol', RolUsuario::Chofer)
            ->whereHas('turnoAbierto')
            ->with(['ubicacion', 'turnoAbierto.vehiculo'])
            ->orderBy('id')
            ->get();
        if ($choferes->isEmpty()) {
            return collect();
        }

        $ids = $choferes->modelKeys();
        $enViaje = Viaje::activos()->whereIn('chofer_id', $ids)->distinct()->pluck('chofer_id')->flip();
        $conReserva = $this->reservasProximas()->whereIn('chofer_id', $ids)->distinct()->pluck('chofer_id')->flip();
        $limiteSenal = $this->limiteSenal();

        return $choferes->map(fn (Usuario $c) => ['chofer' => $c, 'estado' => $this->segun(
            $c->turnoAbierto->cierre_pendiente_en !== null,
            $c->ubicacion,
            fn () => $enViaje->has($c->id),
            fn () => $conReserva->has($c->id),
            $limiteSenal,
        )]);
    }

    /** @return Collection<int, Usuario> */
    public function libres(): Collection
    {
        return $this->choferesEnTurno()
            ->filter(fn (array $f) => $f['estado'] === EstadoChofer::Libre)
            ->map(fn (array $f) => $f['chofer'])
            ->values();
    }

    /**
     * El estado de un chofer en turno. Lo que falta saber se pide con closures, así estado() consulta
     * solo lo necesario.
     *
     * Con cierre pendiente (fichó la salida durante un viaje) el turno sigue abierto solo para terminar ese
     * viaje: sin viaje activo cuenta como fuera de turno, así no recibe viajes ni ofertas nuevas en ningún
     * camino (despacho, asignación, ofertas) mientras se le cierra el turno.
     */
    private function segun(bool $cierrePendiente, ?UbicacionChofer $ubicacion, callable $tieneViajeActivo, callable $tieneReservaProxima, Carbon $limiteSenal): EstadoChofer
    {
        if ($cierrePendiente && ! $tieneViajeActivo()) {
            return EstadoChofer::FueraDeTurno;
        }

        if (! $ubicacion || $ubicacion->actualizado_en->lt($limiteSenal)) {
            return EstadoChofer::SinSenal;
        }

        if ($tieneViajeActivo()) {
            return EstadoChofer::EnViaje;
        }

        if ($tieneReservaProxima()) {
            return EstadoChofer::ReservadoPronto;
        }

        return EstadoChofer::Libre;
    }

    private function limiteSenal(): Carbon
    {
        return now()->subMinutes($this->parametros->entero('sin_senal_min'));
    }

    /** Reservas y viajes largos aceptados que empiezan dentro del bloqueo previo (spec 4.1: "reservado pronto"). */
    private function reservasProximas(): Builder
    {
        return Viaje::whereIn('tipo', TipoViaje::agendados())
            ->where('estado', EstadoViaje::Aceptado)
            ->whereBetween('programado_para', [
                now(), now()->addMinutes($this->parametros->entero('bloqueo_antes_reserva_min')),
            ]);
    }
}
