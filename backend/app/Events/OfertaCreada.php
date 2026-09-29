<?php

namespace App\Events;

use App\Http\Resources\ViajeResource;
use App\Models\OfertaViaje;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class OfertaCreada implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public OfertaViaje $oferta) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chofer.{$this->oferta->chofer_id}")];
    }

    public function broadcastAs(): string
    {
        return 'oferta.creada';
    }

    public function broadcastWith(): array
    {
        return [
            'oferta_id' => $this->oferta->id,
            'vence_en' => $this->oferta->vence_en->toIso8601String(),
            'viaje' => (new ViajeResource($this->oferta->viaje->load(['chofer', 'vehiculo', 'solicitante'])))->resolve(),
        ];
    }
}
