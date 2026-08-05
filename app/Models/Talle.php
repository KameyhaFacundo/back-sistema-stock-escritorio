<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Talle extends Model
{
    use HasFactory, HasTenant;

    protected $table = 'talles';

    protected $fillable = [
        'empresa_id',
        'id_grupo_talle',
        'valor',
        'orden',
        'cantidad_curva',
    ];

    public function grupo()
    {
        return $this->belongsTo(GrupoTalle::class, 'id_grupo_talle', 'id');
    }

    // Variantes de producto que usan este talle puntual.
    public function productos()
    {
        return $this->hasMany(Producto::class, 'id_talle', 'id');
    }
}
