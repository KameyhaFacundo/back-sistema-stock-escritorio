<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class VentaPointIntento extends Model
{
    use HasTenant;

    protected $table = 'venta_point_intentos';

    protected $fillable = [
        'empresa_id',
        'tipo',
        'id_usuario',
        'id_turno',
        'monto',
        'mp_intento_id',
        'estado',
        'venta_payload',
        'id_venta',
    ];

    protected $casts = [
        'monto'         => 'decimal:2',
        'venta_payload' => 'array',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class, 'id_venta');
    }
}
