<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoCliente extends Model
{
    protected $table = 'pagos_cliente';

    protected $fillable = [
        'id_venta', 'id_usuario', 'monto', 'fecha', 'metodo_pago', 'nota',
        'anulado', 'id_usuario_anulacion', 'fecha_anulacion',
    ];

    protected $casts = [
        'monto' => 'decimal:2', 'fecha' => 'date:Y-m-d',
        'anulado' => 'boolean', 'fecha_anulacion' => 'datetime',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class, 'id_venta');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }

    public function usuarioAnulacion()
    {
        return $this->belongsTo(User::class, 'id_usuario_anulacion', 'nro_usu');
    }
}
