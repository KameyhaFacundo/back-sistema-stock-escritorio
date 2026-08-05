<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MercadoPagoConexion extends Model
{
    protected $table = 'mercadopago_conexiones';

    protected $fillable = [
        'empresa_id',
        'mp_user_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'point_device_id',
    ];

    protected $casts = [
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at'    => 'datetime',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
