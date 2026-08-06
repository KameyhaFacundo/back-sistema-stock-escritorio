<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Presupuesto extends Model
{
    use SoftDeletes, HasTenant;

    protected $table = 'presupuestos';

    protected $fillable = [
        'empresa_id', 'id_sucursal',
        'id_cliente', 'id_usuario', 'estado', 'fecha',
        'monto_total', 'notas', 'id_venta',
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
        return $this->hasMany(LineaPresupuesto::class, 'id_presupuesto', 'id');
    }

    public function venta()
    {
        return $this->belongsTo(Venta::class, 'id_venta', 'id');
    }
}
