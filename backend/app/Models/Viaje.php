<?php

namespace App\Models;

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\TipoViaje;
use App\Support\HoraLocal;
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
            'metros_recorridos' => 'integer',
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

    /**
     * Viajes que ocupan al chofer ahora. Los ya iniciados cuentan siempre, incluida una reserva en camino
     * antes de su hora. Los aceptados cuentan salvo que sean reservas todavía a futuro.
     */
    public function scopeActivosDeChofer(Builder $q, int $choferId): Builder
    {
        return $q->where('chofer_id', $choferId)->activos();
    }

    /** Los viajes que ocupan a algún chofer ahora (mismo criterio que activosDeChofer). */
    public function scopeActivos(Builder $q): Builder
    {
        return $q->whereNotNull('chofer_id')
            ->where(fn (Builder $w) => $w
                ->whereIn('estado', [EstadoViaje::EnCamino, EstadoViaje::Llego, EstadoViaje::EnCurso])
                ->orWhere(fn (Builder $a) => $a
                    ->where('estado', EstadoViaje::Aceptado)
                    ->where(fn (Builder $p) => $p->whereNull('programado_para')->orWhere('programado_para', '<=', now()))));
    }

    /** Fecha y hora programadas en la zona de los usuarios, p. ej. "02/10 12:00". */
    public function horaProgramadaLocal(): ?string
    {
        return $this->programado_para ? HoraLocal::formatear($this->programado_para) : null;
    }

    /** ¿Sigue siendo una reserva aceptada de ese chofer para ese momento? La usan los jobs diferidos. */
    public function sigueReservadaPara(int $choferId, int $programadoPara): bool
    {
        return $this->tipo === TipoViaje::Reserva
            && $this->estado === EstadoViaje::Aceptado
            && $this->chofer_id === $choferId
            && $this->programado_para?->getTimestamp() === $programadoPara;
    }
}
