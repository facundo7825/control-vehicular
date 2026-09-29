<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\ResultadoOferta;
use App\Excepciones\ReglaNegocio;
use App\Jobs\VencerOferta;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Busca chofer para viajes inmediatos (spec 5.2 y 5.3). */
class Despachador
{
    public function __construct(
        private Asignador $asignador,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
        private Parametros $parametros,
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
            if ($this->asignador->asignar($viaje, $oferta->chofer)) {
                return;
            }
            $this->seguirBuscando($viaje);

            throw new ReglaNegocio('El viaje ya no está disponible.');
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

        if ($viaje->modo === ModoViaje::Especifico) {
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

        VencerOferta::dispatch($oferta->id)->delay($oferta->vence_en);
        \App\Events\OfertaCreada::dispatch($oferta);

        return true;
    }

    /** @return Collection<int, Usuario> */
    private function candidatos(Viaje $viaje): Collection
    {
        $yaOfrecidos = OfertaViaje::where('viaje_id', $viaje->id)->pluck('chofer_id');

        return $this->estados->libres()
            ->reject(fn (Usuario $c) => $yaOfrecidos->contains($c->id) || $this->tieneOfertaPendiente($c->id))
            ->values();
    }

    private function tieneOfertaPendiente(int $choferId): bool
    {
        return OfertaViaje::where('chofer_id', $choferId)
            ->where('resultado', ResultadoOferta::Pendiente)
            ->where('vence_en', '>', now())
            ->exists();
    }
}
