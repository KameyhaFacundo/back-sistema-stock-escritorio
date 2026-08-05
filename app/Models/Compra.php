<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Compra extends Model
{
    use HasFactory, SoftDeletes, HasTenant;

    protected $table = 'compras';

    protected $fillable = [
        'empresa_id', 'id_sucursal',
        'id_proveedor', 'id_usuario', 'estado', 'fecha',
        'metodo_pago', 'estado_deuda', 'monto_pagado', 'monto_total', 'cuit',
        'id_usuario_anulacion', 'fecha_anulacion',
    ];

    protected $casts = [
        'fecha'           => 'date:Y-m-d',
        'monto_total'     => 'decimal:2',
        'monto_pagado'    => 'decimal:2',
        'fecha_anulacion' => 'datetime',
    ];

    public function getSaldoPendienteAttribute(): float
    {
        return max(0, (float)$this->monto_total - (float)$this->monto_pagado);
    }

    // Relaciones
    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'id_proveedor', 'id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }

    public function usuarioAnulacion()
    {
        return $this->belongsTo(User::class, 'id_usuario_anulacion', 'nro_usu');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal', 'id');
    }

    public function lineas()
    {
        return $this->hasMany(LineaCompra::class, 'id_compra', 'id');
    }

    public function pagos()
    {
        return $this->hasMany(PagoProveedor::class, 'id_compra');
    }

    public function devoluciones()
    {
        return $this->hasMany(DevolucionCompra::class, 'id_compra', 'id');
    }
}
