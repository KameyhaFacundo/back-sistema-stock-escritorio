<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LineaCompra extends Model
{
    use HasFactory;

    protected $table = 'lineas_compras';
    protected $primaryKey = 'id_linea';

    protected $fillable = [
        'id_compra',
        'id_producto',
        'precio_compra',
        'precio_venta',
        'cantidad',
    ];

    protected $casts = [
        'precio_compra' => 'decimal:2',
        'precio_venta'  => 'decimal:2',
        'cantidad'      => 'decimal:2',
    ];

    // Relaciones
    public function compra()
    {
        return $this->belongsTo(Compra::class, 'id_compra', 'id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto', 'id');
    }
}
