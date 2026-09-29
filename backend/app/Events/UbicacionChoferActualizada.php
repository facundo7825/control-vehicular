<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class UbicacionChoferActualizada implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $choferId,
        public float $lat,
        public float $lng,
        public ?float $rumbo,
        public string $actualizadoEn,
        public ?int $viajeId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_filter([
            new PrivateChannel('mapa.choferes'),
            $this->viajeId ? new PrivateChannel("viaje.{$this->viajeId}") : null,
        ]);
    }

    public function broadcastAs(): string
    {
        return 'chofer.ubicacion';
    }

    public function broadcastWith(): array
    {
        return [
            'chofer_id' => $this->choferId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'rumbo' => $this->rumbo,
            'actualizado_en' => $this->actualizadoEn,
        ];
    }
}
