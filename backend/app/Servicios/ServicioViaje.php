<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Excepciones\ReglaNegocio;
use App\Models\CargoPrioritario;
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
}
