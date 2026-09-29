<?php

namespace App\Servicios;

use App\Enums\EstadoViaje as E;
use App\Events\EstadoChoferActualizado;
use App\Events\ViajeActualizado;
use App\Excepciones\TransicionInvalida;
use App\Models\Viaje;

/** Única puerta para cambiar el estado de un viaje (spec 5.1). */
class MaquinaEstadosViaje
{
    public function __construct(private CalculadorEstadoChofer $estados) {}

    private const PERMITIDAS = [
        'buscando' => [E::Ofrecido, E::Aceptado, E::SinChofer, E::Cancelado],
        'ofrecido' => [E::Buscando, E::Aceptado, E::SinChofer, E::Cancelado],
        'aceptado' => [E::EnCamino, E::Buscando, E::Cancelado],
        'en_camino' => [E::Llego, E::Buscando, E::Cancelado],
        'llego' => [E::EnCurso, E::Buscando, E::Cancelado],
        'en_curso' => [E::Finalizado],
    ];

    private const MARCAS = [
        'aceptado' => 'aceptado_en',
        'llego' => 'llego_en',
        'en_curso' => 'iniciado_en',
        'finalizado' => 'finalizado_en',
        'cancelado' => 'cancelado_en',
    ];

    public function puede(E $desde, E $hacia): bool
    {
        return in_array($hacia, self::PERMITIDAS[$desde->value] ?? [], true);
    }

    public function transicionar(Viaje $viaje, E $hacia, array $atributos = []): bool
    {
        if ($viaje->estado === $hacia) {
            return false;
        }

        if (! $this->puede($viaje->estado, $hacia)) {
            throw new TransicionInvalida("El viaje no puede pasar de {$viaje->estado->value} a {$hacia->value}.");
        }

        $choferAnterior = $viaje->chofer_id;

        $viaje->fill($atributos);
        $viaje->estado = $hacia;
        if ($marca = self::MARCAS[$hacia->value] ?? null) {
            $viaje->{$marca} = now();
        }
        $viaje->save();

        ViajeActualizado::dispatch($viaje, $choferAnterior !== $viaje->chofer_id ? $choferAnterior : null);
        foreach (array_unique(array_filter([$choferAnterior, $viaje->chofer_id])) as $choferId) {
            $this->emitirEstadoChofer($choferId);
        }

        return true;
    }

    private function emitirEstadoChofer(int $choferId): void
    {
        $chofer = \App\Models\Usuario::find($choferId);
        EstadoChoferActualizado::dispatch($choferId, $this->estados->estado($chofer)->value);
    }
}
