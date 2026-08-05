<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LineaVenta extends Model
{
    use HasFactory;

    protected $table = 'lineas_ventas';
    protected $primaryKey = 'id_linea';

    protected $fillable = [
        'id_venta',
        'id_producto',
        'nombre',
        'precio_venta',
        'precio_original',
        'cantidad',
    ];

    protected $casts = [
        'precio_venta'    => 'decimal:2',
        'precio_original' => 'decimal:2',
        'cantidad'        => 'decimal:2',
    ];

    // Relaciones
    public function venta()
    {
        return $this->belongsTo(Venta::class, 'id_venta', 'id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto', 'id');
    }
}
