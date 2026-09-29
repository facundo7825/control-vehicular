<?php

namespace App\Models;

use App\Enums\ResultadoOferta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfertaViaje extends Model
{
    protected $table = 'ofertas_viaje';

    protected $fillable = ['viaje_id', 'chofer_id', 'resultado', 'ofrecido_en', 'vence_en', 'respondido_en', 'motivo'];

    protected function casts(): array
    {
        return [
            'resultado' => ResultadoOferta::class,
            'ofrecido_en' => 'datetime', 'vence_en' => 'datetime', 'respondido_en' => 'datetime',
        ];
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'chofer_id');
    }
}
