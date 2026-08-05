<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DevolucionCompra extends Model
{
    use HasFactory, HasTenant;

    protected $table = 'devoluciones_compra';

    protected $fillable = [
        'empresa_id', 'id_compra', 'id_usuario', 'motivo',
        'monto_devuelto', 'monto_efectivo_devuelto', 'caja_ajustada',
    ];

    protected $casts = [
        'monto_devuelto'          => 'decimal:2',
        'monto_efectivo_devuelto' => 'decimal:2',
        'caja_ajustada'           => 'boolean',
    ];

    public function compra()
    {
        return $this->belongsTo(Compra::class, 'id_compra', 'id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }

    public function lineas()
    {
        return $this->hasMany(DevolucionCompraLinea::class, 'id_devolucion_compra', 'id');
    }
}
