<?php

namespace App\Models;

use App\Enums\EstadoViaje;
use Illuminate\Database\Eloquent\Model;

/** Una acción del chofer sobre un viaje, con la hora en que la tocó y la hora en que llegó al servidor. */
class AccionViaje extends Model
{
    protected $table = 'acciones_viaje';

    public $timestamps = false;

    protected $guarded = ['id'];

    /** A partir de esta demora entre la acción y su llegada, el panel la marca como registrada sin señal. */
    public const DEMORA_SIN_SENAL_MIN = 2;

    protected function casts(): array
    {
        return [
            'viaje_id' => 'integer',
            'chofer_id' => 'integer',
            'estado' => EstadoViaje::class,
            'momento' => 'datetime',
            'aplicada_en' => 'datetime',
        ];
    }

    public function llegoSinSenal(): bool
    {
        return $this->momento->diffInSeconds($this->aplicada_en) > self::DEMORA_SIN_SENAL_MIN * 60;
    }
}
