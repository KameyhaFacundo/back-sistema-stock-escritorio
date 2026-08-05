<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venta extends Model
{
    use HasFactory, SoftDeletes, HasTenant;

    protected $table = 'ventas';

    protected $fillable = [
        'empresa_id', 'id_sucursal',
        'numero_ticket', 'id_turno', 'id_cliente', 'id_usuario',
        'estado', 'fecha', 'hora', 'metodo_pago',
        'estado_pago', 'monto_cobrado', 'monto_total', 'cuit',
    ];

    protected $casts = [
        'fecha'       => 'date:Y-m-d',
        'monto_total' => 'decimal:2',
    ];

    // Relaciones
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal', 'id');
    }

    public function lineas()
    {
        return $this->hasMany(LineaVenta::class, 'id_venta', 'id');
    }

    public function pagos()
    {
        return $this->hasMany(PagoCliente::class, 'id_venta');
    }

    public function devoluciones()
    {
        return $this->hasMany(DevolucionVenta::class, 'id_venta', 'id');
    }

    public function factura()
    {
        return $this->hasOne(Factura::class, 'id_venta', 'id');
    }

    public function getSaldoPendienteAttribute(): float
    {
        return max(0, (float)$this->monto_total - (float)$this->monto_cobrado);
    }
}
