<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlantillaEtiqueta extends Model
{
    protected $table = 'plantillas_etiqueta';

    protected $fillable = ['empresa_id', 'nombre', 'config'];

    protected $casts = [
        'config' => 'json',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (!$model->empresa_id) {
                $model->empresa_id = auth()->user()->empresa_id;
            }
        });
    }
}
