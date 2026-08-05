<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PermisoUsuario extends Model
{
    use HasFactory;

    protected $table = 'permisos_usuarios';

    protected $fillable = [
        'id_permiso',
        'id_usuario',
    ];

    public function permiso()
    {
        return $this->belongsTo(Permiso::class, 'id_permiso', 'id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }
}
