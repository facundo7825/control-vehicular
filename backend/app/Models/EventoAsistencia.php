<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fichaje de entrada o salida recibido del sistema de asistencia del PJ y lo que se hizo con él. */
class EventoAsistencia extends Model
{
    public const ENTRADA = 'entrada';

    public const SALIDA = 'salida';

    public const ABIERTO = 'abierto';

    public const CERRADO = 'cerrado';

    public const CIERRE_PENDIENTE = 'cierre_pendiente';

    public const IGNORADO = 'ignorado';

    public const SIN_VEHICULO = 'sin_vehiculo';

    protected $table = 'eventos_asistencia';

    protected $fillable = ['id_evento', 'usuario_id', 'id_externo', 'tipo', 'momento', 'resultado', 'motivo'];

    protected function casts(): array
    {
        return ['momento' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
