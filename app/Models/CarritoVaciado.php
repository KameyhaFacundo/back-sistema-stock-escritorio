<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class CarritoVaciado extends Model
{
    use HasTenant;

    protected $table = 'carritos_vaciados';

    protected $fillable = [
        'empresa_id', 'id_sucursal', 'id_usuario', 'items', 'total',
    ];

    protected $casts = [
        'items' => 'array',
        'total' => 'float',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal');
    }
}
