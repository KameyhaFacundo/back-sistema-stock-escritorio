<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $primaryKey = 'nro_usu';

    protected $fillable = [
        'des_usu',
        'email',
        'email_pendiente',
        'password',
        'google_id',
        'id_tipo_usuario',
        'id_rol',
        'is_super_admin',
        'empresa_id',
        'id_sucursal',
    ];

    // email_token es el hash del token de confirmación de cambio de email
    // (mismo criterio que password_reset_tokens.token) — nunca debe salir en
    // ningún JSON, igual que la propia contraseña.
    protected $hidden = [
        'password',
        'two_factor_secret',
        'email_token',
        'email_token_created_at',
    ];

    protected $casts = [
        'is_super_admin'     => 'boolean',
        'email_verified_at'  => 'datetime',
        'password'           => 'hashed',
        'two_factor_enabled' => 'boolean',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal', 'id');
    }

    public function tipoUsuario()
    {
        return $this->belongsTo(TipoUsuario::class, 'id_tipo_usuario', 'id');
    }

    public function rol()
    {
        return $this->belongsTo(Rol::class, 'id_rol', 'id');
    }

    /**
     * Único mecanismo de "admin total": tener asignado el rol global "Administrador"
     * (codigo = 'admin'), que ya viene con todos los permisos sincronizados.
     */
    public function esAdministrador(): bool
    {
        return $this->rol?->codigo === 'admin';
    }

    public function permisos()
    {
        return $this->belongsToMany(Permiso::class, 'permisos_usuarios', 'id_usuario', 'id_permiso');
    }

    public function permisosUsuarios()
    {
        return $this->hasMany(PermisoUsuario::class, 'id_usuario', 'nro_usu');
    }

    /**
     * Verifica si el usuario tiene los permisos especificados.
     * El rol NO otorga permisos por sí solo — es solo una etiqueta/plantilla.
     * Lo único que determina el acceso real son los permisos directos del usuario.
     */
    public function chequearPermisos($permisosRequeridos): bool
    {
        if (is_string($permisosRequeridos)) {
            $permisosRequeridos = [$permisosRequeridos];
        }

        $permisosUsuario = $this->permisos()->pluck('codigo');

        foreach ($permisosRequeridos as $permiso) {
            if (!$permisosUsuario->contains($permiso)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Obtiene todos los permisos directos del usuario (el rol es solo una etiqueta).
     */
    public function obtenerTodosLosPermisos()
    {
        return $this->permisos;
    }
}
