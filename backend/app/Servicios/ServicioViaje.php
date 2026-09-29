<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Models\CargoPrioritario;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;

class ServicioViaje
{
    public function __construct(
        private Despachador $despachador,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
    ) {}

    public function pedir(Usuario $solicitante, array $datos): Viaje
    {
        $enProgreso = Viaje::where('solicitante_id', $solicitante->id)
            ->where('tipo', TipoViaje::Inmediato)
            ->whereIn('estado', EstadoViaje::enProgreso())
            ->exists();
        if ($enProgreso) {
            throw new ReglaNegocio('Ya tenés un viaje en curso.');
        }

        $modo = ModoViaje::from($datos['modo']);
        $chofer = null;
        if ($modo === ModoViaje::Especifico) {
            $chofer = Usuario::where('rol', RolUsuario::Chofer)->find($datos['chofer_id'])
                ?? throw new ReglaNegocio('El chofer elegido no existe.');
            if ($this->estados->estado($chofer) !== EstadoChofer::Libre) {
                throw new ReglaNegocio('El chofer elegido no está disponible.');
            }
        }

        $viaje = Viaje::create([
            'solicitante_id' => $solicitante->id,
            'tipo' => TipoViaje::Inmediato,
            'modo' => $modo,
            'obligatorio' => CargoPrioritario::esObligatorio($solicitante->cargo),
            'origen_lat' => $datos['origen_lat'],
            'origen_lng' => $datos['origen_lng'],
            'origen_direccion' => $datos['origen_direccion'] ?? null,
            'destino_lat' => $datos['destino_lat'],
            'destino_lng' => $datos['destino_lng'],
            'destino_direccion' => $datos['destino_direccion'] ?? null,
            'motivo' => $datos['motivo'] ?? null,
            'estado' => EstadoViaje::Buscando,
        ]);

        $chofer
            ? $this->despachador->pedirA($viaje, $chofer)
            : $this->despachador->despachar($viaje);

        return $viaje->refresh()->load(['chofer', 'vehiculo', 'solicitante']);
    }

    private const PASOS_CHOFER = [EstadoViaje::EnCamino, EstadoViaje::Llego, EstadoViaje::EnCurso, EstadoViaje::Finalizado];

    public function avanzar(Viaje $viaje, Usuario $chofer, EstadoViaje $hacia): Viaje
    {
        if ($viaje->chofer_id !== $chofer->id) {
            throw new AccionNoPermitida('Este viaje no es tuyo.');
        }
        if (! in_array($hacia, self::PASOS_CHOFER, true)) {
            throw new ReglaNegocio('Estado no válido para el chofer.');
        }

        $this->maquina->transicionar($viaje, $hacia);

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    public function cancelarPorSolicitante(Viaje $viaje, Usuario $solicitante, ?string $motivo): Viaje
    {
        if ($viaje->solicitante_id !== $solicitante->id) {
            throw new AccionNoPermitida('Este viaje no es tuyo.');
        }

        $this->maquina->transicionar($viaje, EstadoViaje::Cancelado, [
            'cancelado_por' => 'solicitante',
            'motivo_cancelacion' => $motivo,
        ]);

        OfertaViaje::where('viaje_id', $viaje->id)
            ->where('resultado', ResultadoOferta::Pendiente)
            ->update(['resultado' => ResultadoOferta::Expirada, 'respondido_en' => now()]);

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    public function cancelarPorChofer(Viaje $viaje, Usuario $chofer, string $motivo): Viaje
    {
        if ($viaje->chofer_id !== $chofer->id) {
            throw new AccionNoPermitida('Este viaje no es tuyo.');
        }
        if ($viaje->obligatorio) {
            throw new AccionNoPermitida('Los viajes obligatorios solo puede cancelarlos un administrador.');
        }
        if (! in_array($viaje->estado, [EstadoViaje::Aceptado, EstadoViaje::EnCamino, EstadoViaje::Llego], true)) {
            throw new ReglaNegocio('El viaje ya no se puede cancelar.');
        }

        // Queda registrado como rechazo: el despachador no volverá a ofrecérselo.
        OfertaViaje::create([
            'viaje_id' => $viaje->id,
            'chofer_id' => $chofer->id,
            'resultado' => ResultadoOferta::Rechazada,
            'ofrecido_en' => $viaje->aceptado_en ?? now(),
            'vence_en' => now(),
            'respondido_en' => now(),
            'motivo' => $motivo,
        ]);

        $this->maquina->transicionar($viaje, EstadoViaje::Buscando, [
            'chofer_id' => null,
            'vehiculo_id' => null,
            'modo' => ModoViaje::MasCercano,
        ]);
        $this->despachador->despachar($viaje);

        return $viaje->refresh()->load(['chofer', 'vehiculo', 'solicitante']);
    }
}
