<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrupoTalle extends Model
{
    use HasFactory, HasTenant;

    protected $table = 'grupos_talles';

    protected $fillable = [
        'empresa_id',
        'nombre',
    ];

    public function talles()
    {
        return $this->hasMany(Talle::class, 'id_grupo_talle', 'id')->orderBy('orden');
    }

    public function productos()
    {
        return $this->hasMany(Producto::class, 'id_grupo_talle', 'id');
    }
}
