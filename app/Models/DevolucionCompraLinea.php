<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DevolucionCompraLinea extends Model
{
    use HasFactory;

    protected $table = 'devoluciones_compra_lineas';

    protected $fillable = [
        'id_devolucion_compra', 'id_linea_compra', 'id_producto', 'cantidad', 'precio_unitario',
    ];

    protected $casts = [
        'cantidad'        => 'decimal:2',
        'precio_unitario' => 'decimal:2',
    ];

    public function devolucion()
    {
        return $this->belongsTo(DevolucionCompra::class, 'id_devolucion_compra', 'id');
    }

    public function lineaCompra()
    {
        return $this->belongsTo(LineaCompra::class, 'id_linea_compra', 'id_linea');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto', 'id');
    }
}
