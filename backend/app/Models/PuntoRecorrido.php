<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PuntoRecorrido extends Model
{
    protected $table = 'recorrido_viaje';

    public $timestamps = false;

    protected $fillable = ['viaje_id', 'lat', 'lng', 'registrado_en'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'registrado_en' => 'datetime'];
    }
}
