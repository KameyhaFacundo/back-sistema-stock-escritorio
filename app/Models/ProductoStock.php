<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class ProductoStock extends Model
{
    use HasTenant;

    protected $table = 'producto_stock';

    protected $fillable = [
        'empresa_id',
        'id_producto',
        'id_sucursal',
        'stock',
        'stock_minimo',
    ];

    protected $casts = [
        'stock'        => 'decimal:2',
        'stock_minimo' => 'decimal:2',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto', 'id');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal', 'id');
    }
}
