<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EstadoChoferActualizado implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $choferId, public string $estado) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('mapa.choferes')];
    }

    public function broadcastAs(): string
    {
        return 'chofer.estado';
    }

    public function broadcastWith(): array
    {
        return ['chofer_id' => $this->choferId, 'estado' => $this->estado];
    }
}
