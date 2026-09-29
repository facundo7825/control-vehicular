<?php

namespace App\Events;

use App\Http\Resources\ViajeResource;
use App\Models\Viaje;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ViajeActualizado implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  array<int, int>  $choferesConOferta  choferes que tenían una oferta pendiente del viaje */
    public function __construct(
        public Viaje $viaje,
        public ?int $choferAnteriorId = null,
        public array $choferesConOferta = [],
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $choferes = array_unique(array_filter([
            $this->viaje->chofer_id, $this->choferAnteriorId, ...$this->choferesConOferta,
        ]));

        return [
            new PrivateChannel("viaje.{$this->viaje->id}"),
            ...array_map(fn (int $id) => new PrivateChannel("chofer.$id"), $choferes),
        ];
    }

    public function broadcastAs(): string
    {
        return 'viaje.actualizado';
    }

    public function broadcastWith(): array
    {
        return (new ViajeResource($this->viaje->loadMissing(['chofer', 'vehiculo', 'solicitante'])))->resolve();
    }
}
