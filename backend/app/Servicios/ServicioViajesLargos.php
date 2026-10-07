<?php

namespace App\Servicios;

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\TipoViaje;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\Support\HoraLocal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Viajes largos (al interior o a otra provincia): los carga el encargado desde el panel y los asigna directo,
 * aceptados, a un chofer y un vehículo. Ocupan la agenda del chofer y el vehículo durante toda la franja
 * (salida → regreso estimado). Mismos locks que las reservas: el viaje, después el chofer y al final el vehículo
 * (el mismo orden que ServicioTurnos, chofer → vehículo).
 */
class ServicioViajesLargos
{
    public const MAX_DIAS = 7;

    public function __construct(
        private DisponibilidadReservas $disponibilidad,
        private MaquinaEstadosViaje $maquina,
        private AvisosReserva $avisosReserva,
        private CompletadorDirecciones $direcciones,
        private Parametros $parametros,
    ) {}

    /**
     * @param  array{solicitante_id: int, chofer_id: int, vehiculo_id: int, programado_para: string, regreso_estimado: string,
     *     origen_lat: float, origen_lng: float, destino_lat: float, destino_lng: float, origen_direccion?: ?string,
     *     destino_direccion?: ?string, motivo?: ?string, pasajeros?: ?string}  $datos  horas locales sin offset, como la app
     */
    public function crear(array $datos, Usuario $admin): Viaje
    {
        if (! $admin->esAdmin()) {
            throw new AccionNoPermitida('Solo un administrador puede cargar viajes largos.');
        }

        [$salida, $regreso] = $this->franja($datos['programado_para'] ?? null, $datos['regreso_estimado'] ?? null);

        $solicitante = Usuario::where('activo', true)->find($datos['solicitante_id'] ?? null)
            ?? throw new ReglaNegocio('El solicitante elegido no existe o no está activo.');

        // Antes de la transacción: la consulta de las direcciones que faltan no retiene ningún lock.
        $datos = $this->direcciones->completar($datos);

        $viaje = DB::transaction(function () use ($datos, $solicitante, $salida, $regreso) {
            // Se crea sin chofer ni vehículo y se asigna después de bloquearlos: así la fila nueva no
            // aparece en las lecturas bloqueantes de otra asignación al mismo chofer o vehículo.
            $viaje = Viaje::create([
                'solicitante_id' => $solicitante->id,
                'tipo' => TipoViaje::Largo,
                'modo' => ModoViaje::Especifico,
                'obligatorio' => false,
                'origen_lat' => $datos['origen_lat'],
                'origen_lng' => $datos['origen_lng'],
                'origen_direccion' => $datos['origen_direccion'] ?? null,
                'destino_lat' => $datos['destino_lat'],
                'destino_lng' => $datos['destino_lng'],
                'destino_direccion' => $datos['destino_direccion'] ?? null,
                'motivo' => self::textoOpcional($datos['motivo'] ?? null),
                'pasajeros' => self::textoOpcional($datos['pasajeros'] ?? null),
                'programado_para' => $salida,
                'regreso_estimado' => $regreso,
                'duracion_estimada_min' => self::duracion($salida, $regreso),
                'estado' => EstadoViaje::Buscando,
            ]);

            [$chofer, $vehiculo] = $this->bloquearYValidar($viaje, (int) ($datos['chofer_id'] ?? 0), (int) ($datos['vehiculo_id'] ?? 0));

            $this->maquina->asignar($viaje, $chofer->id, $vehiculo->id);

            return $viaje;
        }, attempts: 3);

        $this->direcciones->reintentarSiFalta($viaje);
        $viaje->refresh();
        $this->avisosReserva->programar($viaje);

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    /** Cambia el chofer y/o el vehículo de un viaje largo mientras el chofer no haya salido. */
    public function reasignar(Viaje $viaje, Usuario $chofer, Vehiculo $vehiculo): Viaje
    {
        $cambioChofer = DB::transaction(function () use ($viaje, $chofer, $vehiculo) {
            $viaje->setRawAttributes(Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);

            if ($viaje->tipo !== TipoViaje::Largo) {
                throw new ReglaNegocio('El viaje no es un viaje largo.');
            }
            if ($viaje->estado !== EstadoViaje::Aceptado) {
                throw new ReglaNegocio('El viaje largo ya comenzó o terminó; no se puede reasignar.');
            }
            if ($viaje->chofer_id === $chofer->id && $viaje->vehiculo_id === $vehiculo->id) {
                throw new ReglaNegocio('El viaje ya está asignado a ese chofer con ese vehículo.');
            }

            $anterior = $viaje->chofer_id;
            [$c, $v] = $this->bloquearYValidar($viaje, $chofer->id, $vehiculo->id);

            $this->maquina->reasignar($viaje, $c->id, $v->id);

            return $anterior !== $c->id;
        }, attempts: 3);

        $viaje->refresh();

        if ($cambioChofer) {
            // Los recordatorios del chofer anterior quedan sin efecto por sigueReservadaPara().
            $this->avisosReserva->programar($viaje);
        }

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    /**
     * Salida y regreso estimado en la zona de la app. La salida tiene que ser futura y el regreso posterior,
     * como mucho MAX_DIAS días después.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function franja(?string $salida, ?string $regreso): array
    {
        if (blank($salida) || blank($regreso)) {
            throw new ReglaNegocio('Indicá la salida y el regreso estimado.');
        }

        $desde = HoraLocal::interpretar($salida);
        $hasta = HoraLocal::interpretar($regreso);

        if (! $desde->isFuture()) {
            throw new ReglaNegocio('La salida tiene que ser posterior a este momento.');
        }
        if ($hasta->lte($desde)) {
            throw new ReglaNegocio('El regreso estimado tiene que ser posterior a la salida.');
        }
        if ($hasta->gt($desde->copy()->addDays(self::MAX_DIAS))) {
            throw new ReglaNegocio('Un viaje largo puede durar como mucho '.self::MAX_DIAS.' días.');
        }

        return [$desde, $hasta];
    }

    /**
     * Bloquea al chofer y al vehículo (el viaje ya está bloqueado) y valida que estén activos y libres en la
     * franja del viaje. Las lecturas de disponibilidad son FOR UPDATE, como en las reservas.
     *
     * @return array{0: Usuario, 1: Vehiculo}
     */
    private function bloquearYValidar(Viaje $viaje, int $choferId, int $vehiculoId): array
    {
        $chofer = Usuario::whereKey($choferId)->lockForUpdate()->first();
        if (! $chofer?->esChofer() || ! $chofer->activo) {
            throw new ReglaNegocio('El chofer elegido no existe o no está activo.');
        }
        $vehiculo = Vehiculo::whereKey($vehiculoId)->lockForUpdate()->first();
        if (! $vehiculo?->activo) {
            throw new ReglaNegocio('El vehículo elegido no existe o no está activo.');
        }

        $inicio = $viaje->programado_para;
        $duracion = $viaje->duracion_estimada_min;

        if (! $this->disponibilidad->estaDisponible($chofer->id, $inicio, $duracion, excluirViajeId: $viaje->id, bloquear: true)) {
            throw new ReglaNegocio('El chofer elegido tiene otra reserva o viaje largo en esa franja.');
        }
        if (! $this->disponibilidad->vehiculoDisponible($vehiculo->id, $inicio, $duracion, excluirViajeId: $viaje->id, bloquear: true)) {
            throw new ReglaNegocio('El vehículo elegido está en otro viaje largo en esa franja.');
        }
        // Un viaje que sale ya no puede quedar a un chofer que está en otro viaje: tendría dos activos a la vez.
        if ($inicio->lte(now()->addMinutes($this->parametros->entero('bloqueo_antes_reserva_min')))
            && Viaje::activosDeChofer($chofer->id)->whereKeyNot($viaje->id)->exists()) {
            throw new ReglaNegocio('El viaje sale pronto y el chofer elegido está en otro viaje.');
        }

        return [$chofer, $vehiculo];
    }

    private static function duracion(Carbon $salida, Carbon $regreso): int
    {
        return (int) $salida->diffInMinutes($regreso);
    }

    private static function textoOpcional(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }
}
