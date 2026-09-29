<?php

namespace App\Models;

use App\Enums\RolUsuario;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'usuarios';

    protected $fillable = ['id_externo', 'nombre', 'cargo', 'rol', 'telefono', 'token_push', 'activo'];

    protected $hidden = ['token_push'];

    protected function casts(): array
    {
        return ['rol' => RolUsuario::class, 'activo' => 'boolean'];
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(Turno::class, 'chofer_id');
    }

    public function turnoAbierto(): HasOne
    {
        // Invariante (ServicioTurnos): como máximo un turno abierto por chofer.
        return $this->hasOne(Turno::class, 'chofer_id')->whereNull('fin');
    }

    public function ubicacion(): HasOne
    {
        return $this->hasOne(UbicacionChofer::class, 'chofer_id');
    }

    public function esChofer(): bool
    {
        return $this->rol === RolUsuario::Chofer;
    }
}
