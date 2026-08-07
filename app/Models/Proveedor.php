<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use HasFactory, SoftDeletes, HasTenant;

    protected $table = 'proveedores';

    protected $fillable = [
        'empresa_id',
        'cuit',
        'codigo',
        'persona',
        'direccion',
        'telefono',
        'email',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    // Relaciones
    public function compras()
    {
        return $this->hasMany(Compra::class, 'id_proveedor', 'id');
    }
}
