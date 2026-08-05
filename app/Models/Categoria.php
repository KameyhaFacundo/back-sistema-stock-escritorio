<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    use HasFactory, HasTenant;

    protected $table = 'categorias';

    protected $fillable = [
        'empresa_id',
        'categoria',
    ];

    // Relaciones
    public function productos()
    {
        return $this->hasMany(Producto::class, 'id_categoria', 'id');
    }
}
