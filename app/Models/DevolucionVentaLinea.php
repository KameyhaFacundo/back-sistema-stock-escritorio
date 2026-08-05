<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DevolucionVentaLinea extends Model
{
    use HasFactory;

    protected $table = 'devoluciones_venta_lineas';

    protected $fillable = [
        'id_devolucion_venta', 'id_linea_venta', 'id_producto', 'cantidad', 'precio_unitario',
    ];

    protected $casts = [
        'cantidad'        => 'decimal:2',
        'precio_unitario' => 'decimal:2',
    ];

    public function devolucion()
    {
        return $this->belongsTo(DevolucionVenta::class, 'id_devolucion_venta', 'id');
    }

    public function lineaVenta()
    {
        return $this->belongsTo(LineaVenta::class, 'id_linea_venta', 'id_linea');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto', 'id');
    }
}
