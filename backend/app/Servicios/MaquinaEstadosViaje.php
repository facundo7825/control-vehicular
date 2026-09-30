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
    public function __construct(private AvisoEstadoChofer $aviso) {}

    private const PERMITIDAS = [
        'buscando' => [E::Ofrecido, E::Aceptado, E::SinChofer, E::Cancelado],
        'ofrecido' => [E::Buscando, E::Aceptado, E::SinChofer, E::Cancelado],
        // aceptado → sin_chofer: el chofer cancela una reserva, que no se reasigna sola (spec 5.4 y 5.6).
        'aceptado' => [E::EnCamino, E::Buscando, E::Cancelado, E::SinChofer],
        'en_camino' => [E::Llego, E::Buscando, E::Cancelado],
        'llego' => [E::EnCurso, E::Buscando, E::Cancelado],
        'en_curso' => [E::Finalizado],
    ];

    /**
     * Transiciones que solo hace un administrador desde el panel (spec 5.6). Están aparte para que
     * ningún flujo de la app (solicitante o chofer) pueda usarlas: solo transicionarComoAdmin las mira.
     */
    private const SOLO_ADMIN = [
        'en_curso' => [E::Cancelado],
    ];

    /** Estados desde los que el admin puede reasignar el viaje a otro chofer (queda aceptado). */
    private const REASIGNABLES = [E::Buscando, E::Ofrecido, E::Aceptado, E::EnCamino, E::Llego, E::SinChofer];

    private const MARCAS = [
        'aceptado' => 'aceptado_en',
        'llego' => 'llego_en',
        'en_curso' => 'iniciado_en',
        'finalizado' => 'finalizado_en',
        'cancelado' => 'cancelado_en',
    ];

    public function puede(E $desde, E $hacia, bool $comoAdmin = false): bool
    {
        return in_array($hacia, self::PERMITIDAS[$desde->value] ?? [], true)
            || ($comoAdmin && in_array($hacia, self::SOLO_ADMIN[$desde->value] ?? [], true));
    }

    public function transicionar(Viaje $viaje, E $hacia, array $atributos = []): bool
    {
        return $this->aplicar($viaje, $hacia, $atributos, estricto: true);
    }

    /** Como transicionar, pero devuelve false (sin lanzar) si el estado actual ya no lo permite. */
    /**
     * @param  array<int, E>|null  $desde  si se indica, solo transiciona si el estado actual (bloqueado) está en la lista
     */
    public function intentar(Viaje $viaje, E $hacia, array $atributos = [], ?array $desde = null): bool
    {
        return $this->aplicar($viaje, $hacia, $atributos, estricto: false, desde: $desde);
    }

    /** Transición pedida por un administrador: suma las de SOLO_ADMIN y avisa con los textos del panel. */
    public function transicionarComoAdmin(Viaje $viaje, E $hacia, array $atributos = []): bool
    {
        return $this->aplicar($viaje, $hacia, $atributos, estricto: true, comoAdmin: true);
    }

    /**
     * El admin asigna el viaje a otro chofer (spec 5.6). Queda aceptado aunque ya lo estuviera,
     * así que no pasa por aplicar(), que trata "mismo estado" como repetido.
     */
    public function reasignar(Viaje $viaje, int $choferId, ?int $vehiculoId): void
    {
        DB::transaction(function () use ($viaje, $choferId, $vehiculoId) {
            $this->sincronizarConFilaBloqueada($viaje);

            if (! in_array($viaje->estado, self::REASIGNABLES, true)) {
                throw new TransicionInvalida("El viaje no puede reasignarse en estado {$viaje->estado->value}.");
            }

            $this->guardar($viaje, E::Aceptado, [
                'chofer_id' => $choferId,
                'vehiculo_id' => $vehiculoId,
                'llego_en' => null,
            ], porAdmin: true);
        }, attempts: 3);
    }

    private function aplicar(Viaje $viaje, E $hacia, array $atributos, bool $estricto, bool $comoAdmin = false, ?array $desde = null): bool
    {
        return DB::transaction(function () use ($viaje, $hacia, $atributos, $estricto, $comoAdmin, $desde) {
            $this->sincronizarConFilaBloqueada($viaje);

            if ($desde !== null && ! in_array($viaje->estado, $desde, true)) {
                return false;
            }

            if ($viaje->estado === $hacia) {
                return false;
            }

            if (! $this->puede($viaje->estado, $hacia, $comoAdmin)) {
                if (! $estricto) {
                    return false;
                }
                throw new TransicionInvalida("El viaje no puede pasar de {$viaje->estado->value} a {$hacia->value}.");
            }

            $this->guardar($viaje, $hacia, $atributos, porAdmin: $comoAdmin);

            return true;
        }, attempts: 3);
    }

    /**
     * Se valida contra la fila bloqueada, no contra la copia que trae el llamador,
     * para que dos transiciones concurrentes no se pisen.
     */
    private function sincronizarConFilaBloqueada(Viaje $viaje): void
    {
        $actual = Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail();
        $viaje->setRawAttributes($actual->getAttributes(), true);
        $viaje->setRelations([]);
    }

    private function guardar(Viaje $viaje, E $hacia, array $atributos, bool $porAdmin = false): void
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

        ViajeActualizado::dispatch($viaje, $choferAnterior !== $viaje->chofer_id ? $choferAnterior : null, $conOferta, $porAdmin);
        foreach (array_unique(array_filter([$choferAnterior, $viaje->chofer_id])) as $choferId) {
            $this->emitirEstadoChofer($choferId);
        }
    }

    private function emitirEstadoChofer(int $choferId): void
    {
        $chofer = \App\Models\Usuario::find($choferId);
        $this->aviso->publicarSiCambio($chofer);
    }
}
