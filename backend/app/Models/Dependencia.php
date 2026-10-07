<?php

namespace App\Models;

use App\Enums\RolUsuario;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

/** Fuero u oficina: las personas que pertenecen a ella y los choferes que la atienden (varios por chofer). */
class Dependencia extends Model
{
    protected $table = 'dependencias';

    protected $fillable = ['nombre', 'activa'];

    protected $attributes = ['activa' => true];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    /** Sin espacios de más, para que "Fuero  Penal " y "Fuero Penal" sean la misma. */
    protected function nombre(): Attribute
    {
        return Attribute::set(fn (?string $valor) => $valor === null ? null : self::normalizar($valor));
    }

    public function choferes(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'chofer_dependencia', 'dependencia_id', 'chofer_id')
            ->where('rol', RolUsuario::Chofer);
    }

    public function personas(): HasMany
    {
        return $this->hasMany(Usuario::class, 'dependencia_id');
    }

    /** La dependencia de ese nombre, sin distinguir mayúsculas ni espacios de más; si no existe, la crea. */
    public static function buscarOCrear(string $nombre): self
    {
        $nombre = self::normalizar($nombre);

        // Son pocas: se compara en PHP para que las mayúsculas acentuadas den igual en SQLite y en MariaDB.
        $buscar = fn (): ?self => static::all()
            ->first(fn (self $d): bool => mb_strtolower($d->nombre) === mb_strtolower($nombre));

        try {
            return $buscar() ?? static::create(['nombre' => $nombre]);
        } catch (UniqueConstraintViolationException $e) {
            // Otro inicio de sesión la creó al mismo tiempo.
            return $buscar() ?? throw $e;
        }
    }

    public static function normalizar(string $nombre): string
    {
        return trim(preg_replace('/\s+/u', ' ', $nombre));
    }

    /** Si el sistema del PJ informa la dependencia, se fija al iniciar sesión y el panel no la edita. */
    public static function vieneDelPj(): bool
    {
        return config('vehiculos.identidad.driver') === 'poder_judicial'
            && filled(config('vehiculos.identidad.campos.dependencia'));
    }
}
