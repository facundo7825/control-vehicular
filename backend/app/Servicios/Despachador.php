<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\TipoViaje;
use App\Excepciones\ReglaNegocio;
use App\Jobs\VencerOferta;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Busca chofer para viajes inmediatos (spec 5.2 y 5.3) y ofrece reservas a futuro (spec 5.4). */
class Despachador
{
    /** La oferta de una reserva vence, como tarde, esta cantidad de minutos antes del viaje (spec 5.4). */
    private const LIMITE_OFERTA_RESERVA_ANTES_MIN = 60;

    /** Plazo mínimo para responder una oferta de reserva, aunque el viaje esté cerca. */
    private const PLAZO_MINIMO_OFERTA_RESERVA_MIN = 5;

    public function __construct(
        private Asignador $asignador,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
        private Parametros $parametros,
        private DisponibilidadReservas $disponibilidad,
    ) {}

    public function despachar(Viaje $viaje): void
    {
        $viaje->refresh();
        if ($viaje->estado !== EstadoViaje::Buscando) {
            return;
        }

        foreach ($this->asignador->ordenarPorCercania($viaje, $this->candidatos($viaje)) as $chofer) {
            $listo = $viaje->obligatorio
                ? $this->asignador->asignar($viaje, $chofer)
                : $this->ofrecer($viaje, $chofer);

            if ($listo) {
                return;
            }
        }

        // Si lo cancelaron mientras se recorrían los candidatos, no hay nada que marcar.
        $this->maquina->intentar($viaje, EstadoViaje::SinChofer);
    }

    public function pedirA(Viaje $viaje, Usuario $chofer): void
    {
        $listo = $viaje->obligatorio
            ? $this->asignador->asignar($viaje, $chofer)
            : $this->ofrecer($viaje, $chofer);

        if (! $listo) {
            $this->maquina->intentar($viaje, EstadoViaje::SinChofer);
        }
    }

    /** Ofrece una reserva a un chofer, esté o no en turno, si la franja sigue libre en su agenda. */
    public function ofrecerReserva(Viaje $viaje, Usuario $chofer): bool
    {
        $oferta = DB::transaction(function () use ($viaje, $chofer) {
            $v = Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail();
            $c = Usuario::whereKey($chofer->id)->lockForUpdate()->first();

            if ($v->tipo !== TipoViaje::Reserva
                || $v->estado !== EstadoViaje::Buscando
                || ! $c?->esChofer()
                || ! $c->activo
                || ! $this->disponibilidad->estaDisponible(
                    $c->id,
                    $v->programado_para,
                    $v->duracion_estimada_min ?? $this->parametros->entero('duracion_reserva_por_defecto_min'),
                    excluirViajeId: $v->id,
                    bloquear: true,
                )) {
                return null;
            }

            $this->maquina->transicionar($v, EstadoViaje::Ofrecido);

            return OfertaViaje::create([
                'viaje_id' => $v->id,
                'chofer_id' => $c->id,
                'resultado' => ResultadoOferta::Pendiente,
                'ofrecido_en' => now(),
                'vence_en' => $this->venceOfertaReserva($v->programado_para),
            ]);
        });

        if (! $oferta) {
            return false;
        }

        VencerOferta::dispatch($oferta->id)->delay($oferta->vence_en)->afterCommit();
        \App\Events\OfertaCreada::dispatch($oferta);

        return true;
    }

    public function responder(OfertaViaje $oferta, bool $acepta): void
    {
        $resultado = DB::transaction(function () use ($oferta, $acepta) {
            $o = OfertaViaje::whereKey($oferta->id)->lockForUpdate()->firstOrFail();

            if ($o->resultado !== ResultadoOferta::Pendiente) {
                return null;
            }
            if ($o->vence_en->isPast()) {
                $o->update(['resultado' => ResultadoOferta::Expirada, 'respondido_en' => now()]);

                return ResultadoOferta::Expirada;
            }

            $nuevo = $acepta ? ResultadoOferta::Aceptada : ResultadoOferta::Rechazada;
            $o->update(['resultado' => $nuevo, 'respondido_en' => now()]);

            return $nuevo;
        });

        if ($resultado === null) {
            throw new ReglaNegocio('La oferta ya no está vigente.');
        }

        $viaje = $oferta->viaje;

        if ($resultado === ResultadoOferta::Aceptada) {
            // Una reserva no exige que el chofer esté libre ahora: se verifica su agenda (spec 4.2).
            $asignado = $viaje->tipo === TipoViaje::Reserva
                ? $this->asignador->asignarReserva($viaje, $oferta->chofer)
                : $this->asignador->asignar($viaje, $oferta->chofer);
            if ($asignado) {
                return;
            }
            $this->seguirBuscando($viaje);

            throw new ReglaNegocio($viaje->tipo === TipoViaje::Reserva
                ? 'La reserva ya no está disponible o se superpone con otra de tu agenda.'
                : 'El viaje ya no está disponible.');
        }

        $this->seguirBuscando($viaje);

        if ($resultado === ResultadoOferta::Expirada) {
            throw new ReglaNegocio('La oferta venció.');
        }
    }

    public function vencer(OfertaViaje $oferta): void
    {
        $vencida = DB::transaction(function () use ($oferta) {
            $o = OfertaViaje::whereKey($oferta->id)->lockForUpdate()->firstOrFail();
            if ($o->resultado !== ResultadoOferta::Pendiente) {
                return false;
            }
            $o->update(['resultado' => ResultadoOferta::Expirada, 'respondido_en' => now()]);

            return true;
        });

        if ($vencida) {
            $this->seguirBuscando($oferta->viaje);
        }
    }

    private function seguirBuscando(Viaje $viaje): void
    {
        $viaje->refresh();
        if ($viaje->estado !== EstadoViaje::Ofrecido) {
            return; // cancelado o ya resuelto mientras tanto
        }

        // Chofer específico y reservas no se reofrecen: el solicitante elige otro (spec 5.3 y 5.4).
        // Una reserva nunca se despacha como inmediato.
        if ($viaje->modo === ModoViaje::Especifico || $viaje->tipo === TipoViaje::Reserva) {
            $this->maquina->intentar($viaje, EstadoViaje::SinChofer);

            return;
        }

        if ($this->maquina->intentar($viaje, EstadoViaje::Buscando)) {
            $this->despachar($viaje);
        }
    }

    private function ofrecer(Viaje $viaje, Usuario $chofer): bool
    {
        $oferta = DB::transaction(function () use ($viaje, $chofer) {
            $v = Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail();
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();

            if ($v->estado !== EstadoViaje::Buscando
                || $this->estados->estado($chofer) !== EstadoChofer::Libre
                || $this->tieneOfertaPendiente($chofer->id)) {
                return null;
            }

            $this->maquina->transicionar($v, EstadoViaje::Ofrecido);

            return OfertaViaje::create([
                'viaje_id' => $v->id,
                'chofer_id' => $chofer->id,
                'resultado' => ResultadoOferta::Pendiente,
                'ofrecido_en' => now(),
                'vence_en' => now()->addSeconds($this->parametros->entero('oferta_segundos')),
            ]);
        });

        if (! $oferta) {
            return false;
        }

        VencerOferta::dispatch($oferta->id)->delay($oferta->vence_en)->afterCommit();
        \App\Events\OfertaCreada::dispatch($oferta);

        return true;
    }

    /** min(ahora + plazo, programado_para − 60 min), pero nunca antes de ahora + 5 min. */
    private function venceOfertaReserva(Carbon $programadoPara): Carbon
    {
        $vence = now()->addMinutes($this->parametros->entero('plazo_respuesta_reserva_min'));
        $limite = $programadoPara->copy()->subMinutes(self::LIMITE_OFERTA_RESERVA_ANTES_MIN);
        if ($limite->lt($vence)) {
            $vence = $limite;
        }

        $minimo = now()->addMinutes(self::PLAZO_MINIMO_OFERTA_RESERVA_MIN);

        return $vence->lt($minimo) ? $minimo : $vence;
    }

    /** @return Collection<int, Usuario> */
    private function candidatos(Viaje $viaje): Collection
    {
        $yaOfrecidos = OfertaViaje::where('viaje_id', $viaje->id)->pluck('chofer_id');

        return $this->estados->libres()
            ->reject(fn (Usuario $c) => $yaOfrecidos->contains($c->id) || $this->tieneOfertaPendiente($c->id))
            ->values();
    }

    /** Solo cuentan las ofertas de viajes inmediatos: una solicitud de reserva no ocupa al chofer ahora. */
    private function tieneOfertaPendiente(int $choferId): bool
    {
        return OfertaViaje::where('chofer_id', $choferId)
            ->where('resultado', ResultadoOferta::Pendiente)
            ->where('vence_en', '>', now())
            ->whereHas('viaje', fn ($q) => $q->where('tipo', TipoViaje::Inmediato))
            ->exists();
    }
}
