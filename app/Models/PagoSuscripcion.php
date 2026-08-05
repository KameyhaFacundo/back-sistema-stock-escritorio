<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PagoSuscripcion extends Model
{
    use HasFactory;

    protected $table = 'pagos_suscripcion';

    protected $fillable = [
        'empresa_id', 'plan', 'plan_anterior', 'ciclo', 'monto', 'payment_id',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
