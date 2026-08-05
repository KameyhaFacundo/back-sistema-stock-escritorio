<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class Turno extends Model
{
    use HasTenant;

    protected $table = 'turnos';

    protected $fillable = [
        'empresa_id', 'id_sucursal',
        'id_usuario', 'estado', 'fecha', 'hora_apertura', 'hora_cierre',
        'monto_inicial', 'monto_final', 'monto_esperado', 'diferencia',
        'ventas_efectivo', 'efectivo_actual',
    ];

    protected $casts = [
        'fecha'           => 'date:Y-m-d',
        'monto_inicial'   => 'decimal:2',
        'monto_final'     => 'decimal:2',
        'monto_esperado'  => 'decimal:2',
        'diferencia'      => 'decimal:2',
        'ventas_efectivo' => 'decimal:2',
        'efectivo_actual' => 'decimal:2',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal', 'id');
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoCaja::class, 'id_turno');
    }

    public function ventas()
    {
        return $this->hasMany(\App\Models\Venta::class, 'id_turno');
    }
}
