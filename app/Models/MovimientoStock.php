<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class MovimientoStock extends Model
{
    use HasTenant;

    protected $table = 'movimientos_stock';

    protected $fillable = [
        'empresa_id', 'id_sucursal',
        'id_producto', 'id_usuario', 'producto', 'codigo',
        'tipo', 'sub_tipo', 'cantidad', 'nota', 'fecha', 'hora',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal', 'id');
    }
}
