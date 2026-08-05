<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    use HasTenant;

    protected $table = 'sucursales';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'direccion',
        'telefono',
        'activo',
        'es_principal',
    ];

    protected $casts = [
        'activo'       => 'boolean',
        'es_principal' => 'boolean',
    ];

    public function stocks()
    {
        return $this->hasMany(ProductoStock::class, 'id_sucursal', 'id');
    }
}
