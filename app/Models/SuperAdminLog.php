<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuperAdminLog extends Model
{
    protected $table = 'super_admin_logs';

    protected $fillable = [
        'super_admin_id',
        'super_admin_email',
        'empresa_id',
        'empresa_nombre',
        'accion',
        'detalle',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
