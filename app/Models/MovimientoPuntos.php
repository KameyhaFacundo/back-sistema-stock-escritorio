<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class MovimientoPuntos extends Model
{
    use HasTenant;

    protected $table = 'movimientos_puntos';

    protected $fillable = [
        'empresa_id', 'id_cliente', 'id_venta', 'tipo', 'puntos', 'saldo_posterior', 'nota',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function venta()
    {
        return $this->belongsTo(Venta::class, 'id_venta');
    }
}
