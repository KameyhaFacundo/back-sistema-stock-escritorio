<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoProveedor extends Model
{
    protected $table = 'pagos_proveedor';

    protected $fillable = ['id_compra', 'id_usuario', 'monto', 'fecha', 'metodo_pago', 'nota'];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date:Y-m-d',
    ];

    public function compra()
    {
        return $this->belongsTo(Compra::class, 'id_compra');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }
}
