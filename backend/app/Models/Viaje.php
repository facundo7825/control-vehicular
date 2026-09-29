<?php

namespace App\Models;

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\TipoViaje;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Viaje extends Model
{
    use HasFactory;

    protected $table = 'viajes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoViaje::class,
            'modo' => ModoViaje::class,
            'estado' => EstadoViaje::class,
            'obligatorio' => 'boolean',
            'origen_lat' => 'float', 'origen_lng' => 'float',
            'destino_lat' => 'float', 'destino_lng' => 'float',
            'programado_para' => 'datetime',
            'aceptado_en' => 'datetime', 'llego_en' => 'datetime', 'iniciado_en' => 'datetime',
            'finalizado_en' => 'datetime', 'cancelado_en' => 'datetime',
        ];
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitante_id');
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'chofer_id');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function ofertas(): HasMany
    {
        return $this->hasMany(OfertaViaje::class);
    }

    public function recorrido(): HasMany
    {
        return $this->hasMany(PuntoRecorrido::class);
    }

    /** Viajes que ocupan al chofer ahora (excluye reservas aceptadas todavía a futuro). */
    public function scopeActivosDeChofer(Builder $q, int $choferId): Builder
    {
        return $q->where('chofer_id', $choferId)
            ->whereIn('estado', EstadoViaje::conChofer())
            ->where(fn (Builder $w) => $w->whereNull('programado_para')->orWhere('programado_para', '<=', now()));
    }
}
