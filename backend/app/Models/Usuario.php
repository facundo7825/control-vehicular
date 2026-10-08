<?php

namespace App\Models;

use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens, HasFactory;

    protected $table = 'usuarios';

    protected $fillable = ['id_externo', 'nombre', 'cargo', 'rol', 'telefono', 'token_push', 'activo', 'email', 'password', 'vehiculo_habitual_id', 'dependencia_id', 'chofer_asignado_id'];

    protected $hidden = ['token_push', 'password', 'remember_token'];

    protected function casts(): array
    {
        return ['rol' => RolUsuario::class, 'activo' => 'boolean', 'password' => 'hashed'];
    }

    /** Vehículo con el que se abre el turno al fichar la entrada (ServicioAsistencia). */
    public function vehiculoHabitual(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_habitual_id');
    }

    /** Dependencia del solicitante: sus choferes son los segundos en recibir el viaje. */
    public function dependencia(): BelongsTo
    {
        return $this->belongsTo(Dependencia::class);
    }

    /** Chofer asignado a esta persona: el primero en recibir sus viajes. Debe tener rol chofer. */
    public function choferAsignado(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'chofer_asignado_id');
    }

    /** Para un chofer: las dependencias que atiende. */
    public function dependenciasQueAtiende(): BelongsToMany
    {
        return $this->belongsToMany(Dependencia::class, 'chofer_dependencia', 'chofer_id', 'dependencia_id');
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

    /** Turno abierto o viajes asignados: no se le puede quitar el rol de chofer ni desactivarlo. */
    public function tieneTrabajoDeChofer(): bool
    {
        return $this->turnoAbierto()->exists()
            || Viaje::where('chofer_id', $this->id)->whereIn('estado', EstadoViaje::conChofer())->exists();
    }

    /** Borra los tokens de la app que excedan los $maximo más recientes (se permiten varios: más de un dispositivo). */
    public function recortarTokens(int $maximo = 5): void
    {
        $sobrantes = $this->tokens()->orderByDesc('id')->skip($maximo)->take(PHP_INT_MAX)->pluck('id');

        if ($sobrantes->isNotEmpty()) {
            $this->tokens()->whereIn('id', $sobrantes)->delete();
        }
    }

    protected static function booted(): void
    {
        // Desactivar corta el acceso ya: se revocan los tokens de la app (el middleware 'activo' cubre el resto).
        static::updated(function (Usuario $u) {
            if ($u->wasChanged('activo') && ! $u->activo) {
                $u->tokens()->delete();
            }
        });
    }

    /** IDENTIDAD_CAMPO_ROL configurado: si es chofer o solicitante lo define el PJ en cada ingreso. */
    public static function rolVieneDelPj(): bool
    {
        return config('vehiculos.identidad.driver') === 'poder_judicial'
            && filled(config('vehiculos.identidad.campos.rol'));
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
