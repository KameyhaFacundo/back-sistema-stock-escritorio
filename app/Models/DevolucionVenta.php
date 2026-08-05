<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DevolucionVenta extends Model
{
    use HasFactory, HasTenant;

    protected $table = 'devoluciones_venta';

    protected $fillable = [
        'empresa_id', 'id_venta', 'id_usuario', 'motivo',
        'monto_devuelto', 'monto_efectivo_devuelto', 'caja_ajustada',
    ];

    protected $casts = [
        'monto_devuelto'          => 'decimal:2',
        'monto_efectivo_devuelto' => 'decimal:2',
        'caja_ajustada'           => 'boolean',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class, 'id_venta', 'id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }

    public function lineas()
    {
        return $this->hasMany(DevolucionVentaLinea::class, 'id_devolucion_venta', 'id');
    }
}
