<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aviso para el panel de administración (spec 8.6). */
class Alerta extends Model
{
    public const RESERVA_SIN_TURNO = 'reserva_sin_turno';

    /** Spec 9: chofer sin señal durante un viaje. Se resuelve sola (AlertasSinSenal). */
    public const CHOFER_SIN_SENAL = 'chofer_sin_senal';

    /** Un viaje (inmediato o reserva) quedó sin chofer. Se resuelve sola al salir de sin_chofer (MaquinaEstadosViaje). */
    public const VIAJE_SIN_CHOFER = 'viaje_sin_chofer';

    /** El chofer fichó la entrada pero su vehículo habitual falta, está inactivo o en uso (ServicioAsistencia). */
    public const ASISTENCIA_SIN_VEHICULO = 'asistencia_sin_vehiculo';

    protected $table = 'alertas';

    protected $fillable = ['tipo', 'viaje_id', 'chofer_id', 'mensaje', 'resuelta_en'];

    protected function casts(): array
    {
        return ['resuelta_en' => 'datetime'];
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'chofer_id');
    }

    public function scopePendientes(Builder $q): Builder
    {
        return $q->whereNull('resuelta_en');
    }
}
