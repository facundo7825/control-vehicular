<?php

namespace App\Servicios;

use App\Enums\EstadoViaje as E;
use App\Enums\ResultadoOferta;
use App\Events\EstadoChoferActualizado;
use App\Events\ViajeActualizado;
use App\Excepciones\TransicionInvalida;
use App\Models\OfertaViaje;
use App\Models\Viaje;
use Illuminate\Support\Facades\DB;

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
        return $this->aplicar($viaje, $hacia, $atributos, estricto: true);
    }

    /** Como transicionar, pero devuelve false (sin lanzar) si el estado actual ya no lo permite. */
    public function intentar(Viaje $viaje, E $hacia, array $atributos = []): bool
    {
        return $this->aplicar($viaje, $hacia, $atributos, estricto: false);
    }

    private function aplicar(Viaje $viaje, E $hacia, array $atributos, bool $estricto): bool
    {
        return DB::transaction(function () use ($viaje, $hacia, $atributos, $estricto) {
            // Se valida contra la fila bloqueada, no contra la copia que trae el llamador,
            // para que dos transiciones concurrentes no se pisen.
            $actual = Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail();
            $viaje->setRawAttributes($actual->getAttributes(), true);
            $viaje->setRelations([]);

            if ($viaje->estado === $hacia) {
                return false;
            }

            if (! $this->puede($viaje->estado, $hacia)) {
                if (! $estricto) {
                    return false;
                }
                throw new TransicionInvalida("El viaje no puede pasar de {$viaje->estado->value} a {$hacia->value}.");
            }

            $this->guardar($viaje, $hacia, $atributos);

            return true;
        });
    }

    private function guardar(Viaje $viaje, E $hacia, array $atributos): void
    {
        $desde = $viaje->estado;
        $choferAnterior = $viaje->chofer_id;
        // El chofer con una oferta pendiente no es chofer_id del viaje, pero tiene que enterarse del cambio.
        $conOferta = $desde === E::Ofrecido
            ? OfertaViaje::where('viaje_id', $viaje->id)->where('resultado', ResultadoOferta::Pendiente)
                ->pluck('chofer_id')->all()
            : [];

        $viaje->fill($atributos);
        $viaje->estado = $hacia;
        if ($marca = self::MARCAS[$hacia->value] ?? null) {
            $viaje->{$marca} = now();
        }
        $viaje->save();

        ViajeActualizado::dispatch($viaje, $choferAnterior !== $viaje->chofer_id ? $choferAnterior : null, $conOferta);
        foreach (array_unique(array_filter([$choferAnterior, $viaje->chofer_id])) as $choferId) {
            $this->emitirEstadoChofer($choferId);
        }
    }

    private function emitirEstadoChofer(int $choferId): void
    {
        $chofer = \App\Models\Usuario::find($choferId);
        EstadoChoferActualizado::dispatch($choferId, $this->estados->estado($chofer)->value);
    }
}
