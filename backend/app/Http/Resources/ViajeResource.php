<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Viaje */
class ViajeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'modo' => $this->modo->value,
            'estado' => $this->estado->value,
            'obligatorio' => $this->obligatorio,
            'origen' => ['lat' => $this->origen_lat, 'lng' => $this->origen_lng, 'direccion' => $this->origen_direccion],
            'destino' => ['lat' => $this->destino_lat, 'lng' => $this->destino_lng, 'direccion' => $this->destino_direccion],
            'motivo' => $this->motivo,
            'programado_para' => $this->programado_para?->toIso8601String(),
            'duracion_estimada_min' => $this->duracion_estimada_min,
            'chofer' => $this->chofer
                ? ['id' => $this->chofer->id, 'nombre' => $this->chofer->nombre, 'telefono' => $this->chofer->telefono]
                : null,
            'vehiculo' => $this->vehiculo?->only(['patente', 'marca', 'modelo', 'color']),
            'solicitante' => [
                'id' => $this->solicitante->id,
                'nombre' => $this->solicitante->nombre,
                'telefono' => $this->solicitante->telefono,
            ],
            'aceptado_en' => $this->aceptado_en?->toIso8601String(),
            'llego_en' => $this->llego_en?->toIso8601String(),
            'iniciado_en' => $this->iniciado_en?->toIso8601String(),
            'finalizado_en' => $this->finalizado_en?->toIso8601String(),
            'cancelado_en' => $this->cancelado_en?->toIso8601String(),
        ];
    }
}
