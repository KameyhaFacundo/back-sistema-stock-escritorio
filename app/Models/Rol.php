<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    use HasFactory;

    protected $table = 'roles';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
    ];

    public function permisos()
    {
        return $this->belongsToMany(Permiso::class, 'rol_permisos', 'id_rol', 'id_permiso');
    }

    public function usuarios()
    {
        return $this->hasMany(User::class, 'id_rol', 'id');
    }
}
