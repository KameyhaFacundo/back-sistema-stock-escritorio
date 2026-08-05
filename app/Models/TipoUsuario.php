<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoUsuario extends Model
{
    use HasFactory;

    protected $table = 'tipo_usuarios';

    protected $fillable = [
        'codigo',
        'detalle',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'id_tipo_usuario', 'id');
    }
}
