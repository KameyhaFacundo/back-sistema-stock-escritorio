<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RolPermiso extends Model
{
    use HasFactory;

    protected $table = 'rol_permisos';

    public $timestamps = false;

    protected $fillable = [
        'id_rol',
        'id_permiso',
    ];

    public function rol()
    {
        return $this->belongsTo(Rol::class, 'id_rol', 'id');
    }

    public function permiso()
    {
        return $this->belongsTo(Permiso::class, 'id_permiso', 'id');
    }
}
