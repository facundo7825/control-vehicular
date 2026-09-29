<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UbicacionChofer extends Model
{
    protected $table = 'ubicaciones_chofer';

    protected $primaryKey = 'chofer_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['chofer_id', 'lat', 'lng', 'rumbo', 'velocidad', 'actualizado_en'];

    protected function casts(): array
    {
        return [
            'lat' => 'float', 'lng' => 'float', 'rumbo' => 'float',
            'velocidad' => 'float', 'actualizado_en' => 'datetime',
        ];
    }
}
