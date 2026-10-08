<?php

namespace App\Models;

use App\Enums\RolUsuario;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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

    /**
     * La dependencia de ese nombre, sin distinguir mayúsculas, acentos ni espacios de más; si no existe, la
     * crea. Si no se puede crear ni encontrar devuelve null: el inicio de sesión sigue sin cambiarla.
     */
    public static function buscarOCrear(string $nombre): ?self
    {
        $nombre = self::normalizar($nombre);
        $clave = self::clave($nombre);

        // Son pocas: se compara en PHP, igual que la intercalación utf8mb4_unicode_ci de MariaDB, así un nombre
        // que la base considera repetido se encuentra también en SQLite.
        $buscar = fn (): ?self => static::all()->first(fn (self $d): bool => self::clave($d->nombre) === $clave);

        try {
            return $buscar() ?? static::create(['nombre' => $nombre]);
        } catch (UniqueConstraintViolationException $e) {
            // Otro inicio de sesión la creó al mismo tiempo, o la base la considera igual a una existente.
            return $buscar() ?? self::sinDependencia($nombre, $e);
        }
    }

    /** Lo que compara la base: sin espacios de más, sin acentos y en minúsculas. */
    public static function clave(string $nombre): string
    {
        return mb_strtolower(Str::ascii(self::normalizar($nombre)));
    }

    private static function sinDependencia(string $nombre, Throwable $e): null
    {
        Log::warning("No se pudo crear ni encontrar la dependencia \"$nombre\"; el usuario sigue sin cambiarla.", [
            'excepcion' => $e::class,
        ]);

        return null;
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
