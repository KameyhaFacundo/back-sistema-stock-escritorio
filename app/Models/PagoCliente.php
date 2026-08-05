<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoCliente extends Model
{
    protected $table = 'pagos_cliente';

    protected $fillable = ['id_venta', 'id_usuario', 'monto', 'fecha', 'metodo_pago', 'nota'];

    protected $casts = ['monto' => 'decimal:2', 'fecha' => 'date:Y-m-d'];

    public function venta()
    {
        return $this->belongsTo(Venta::class, 'id_venta');
    }
}
