<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class HistorialPermiso extends Model
{
    use HasTenant;

    protected $table = 'historial_permisos';

    protected $fillable = [
        'empresa_id',
        'id_usuario_afectado',
        'id_usuario_actor',
        'permisos_agregados',
        'permisos_quitados',
    ];

    protected $casts = [
        'permisos_agregados' => 'array',
        'permisos_quitados'  => 'array',
    ];

    public function usuarioAfectado()
    {
        return $this->belongsTo(User::class, 'id_usuario_afectado', 'nro_usu');
    }

    public function usuarioActor()
    {
        return $this->belongsTo(User::class, 'id_usuario_actor', 'nro_usu');
    }
}
