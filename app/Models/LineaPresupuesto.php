<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LineaPresupuesto extends Model
{
    protected $table = 'lineas_presupuesto';
    protected $primaryKey = 'id_linea';

    protected $fillable = [
        'id_presupuesto',
        'id_producto',
        'nombre',
        'precio_venta',
        'cantidad',
    ];

    protected $casts = [
        'precio_venta' => 'decimal:2',
        'cantidad'     => 'decimal:2',
    ];

    // Relaciones
    public function presupuesto()
    {
        return $this->belongsTo(Presupuesto::class, 'id_presupuesto', 'id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto', 'id');
    }
}
