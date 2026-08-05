<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PagoFacturacionPack extends Model
{
    use HasFactory;

    protected $table = 'pagos_facturacion_packs';

    protected $fillable = [
        'empresa_id', 'pack', 'cantidad', 'monto', 'payment_id',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
