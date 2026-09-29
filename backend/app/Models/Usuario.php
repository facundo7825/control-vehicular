<?php

namespace App\Models;

use App\Enums\RolUsuario;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens, HasFactory;

    protected $table = 'usuarios';

    protected $fillable = ['id_externo', 'nombre', 'cargo', 'rol', 'telefono', 'token_push', 'activo', 'email', 'password'];

    protected $hidden = ['token_push', 'password', 'remember_token'];

    protected function casts(): array
    {
        return ['rol' => RolUsuario::class, 'activo' => 'boolean', 'password' => 'hashed'];
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

    public function esAdmin(): bool
    {
        return $this->rol === RolUsuario::Admin;
    }

    /** Panel Filament (spec 8): solo administradores activos. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->esAdmin() && $this->activo;
    }

    public function getFilamentName(): string
    {
        return $this->nombre;
    }
}
