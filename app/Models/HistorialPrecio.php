<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistorialPrecio extends Model
{
    protected $table = 'historial_precios';

    protected $fillable = [
        'empresa_id',
        'id_producto',
        'campo',
        'precio_anterior',
        'precio_nuevo',
        'id_usuario',
    ];

    protected $casts = [
        'precio_anterior' => 'decimal:2',
        'precio_nuevo'    => 'decimal:2',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto', 'id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }
}
