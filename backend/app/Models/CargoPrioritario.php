<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CargoPrioritario extends Model
{
    protected $table = 'cargos_prioritarios';

    protected $fillable = ['cargo', 'obligatorio'];

    protected function casts(): array
    {
        return ['obligatorio' => 'boolean'];
    }

    public static function esObligatorio(?string $cargo): bool
    {
        return $cargo !== null
            && static::where('cargo', $cargo)->where('obligatorio', true)->exists();
    }
}
