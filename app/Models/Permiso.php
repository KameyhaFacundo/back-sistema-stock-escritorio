<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    use HasFactory;

    protected $table = 'permisos';

    protected $fillable = [
        'codigo',
        'nombre',
        'grupo',
    ];

    public function usuarios()
    {
        return $this->belongsToMany(User::class, 'permisos_usuarios', 'id_permiso', 'id_usuario');
    }

    public function roles()
    {
        return $this->belongsToMany(Rol::class, 'rol_permisos', 'id_permiso', 'id_rol');
    }
}
