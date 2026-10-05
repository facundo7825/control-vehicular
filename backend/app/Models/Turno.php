<?php

namespace App\Models;

use App\Enums\OrigenTurno;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Turno extends Model
{
    use HasFactory;

    protected $table = 'turnos';

    protected $fillable = ['chofer_id', 'vehiculo_id', 'inicio', 'fin', 'origen', 'cierre_pendiente_en'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fin' => 'datetime', 'cierre_pendiente_en' => 'datetime', 'origen' => OrigenTurno::class];
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'chofer_id');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }
}
