<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehiculo extends Model
{
    use HasFactory;

    protected $table = 'vehiculos';

    protected $fillable = ['patente', 'marca', 'modelo', 'color', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(Turno::class);
    }

    public function viajes(): HasMany
    {
        return $this->hasMany(Viaje::class);
    }

    /** Un vehículo que ya se usó no se borra (lo referencian turnos y viajes): se desactiva. */
    public function tieneHistorial(): bool
    {
        return $this->turnos()->exists() || $this->viajes()->exists();
    }
}
