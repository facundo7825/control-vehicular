<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehiculo extends Model
{
    use HasFactory;

    protected $table = 'vehiculos';

    protected $fillable = ['patente', 'marca', 'modelo', 'color', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
