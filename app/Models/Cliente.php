<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use HasFactory, SoftDeletes, HasTenant;

    protected $table = 'clientes';

    protected $fillable = [
        'empresa_id',
        'cuit',
        'persona',
        'direccion',
        'telefono',
        'email',
        'condicion_iva',
        'estado',
        'puntos',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    // Relaciones
    public function ventas()
    {
        return $this->hasMany(Venta::class, 'id_cliente', 'id');
    }

    public function movimientosPuntos()
    {
        return $this->hasMany(MovimientoPuntos::class, 'id_cliente', 'id');
    }
}
