<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait de multi-tenancy: filtra todas las queries por empresa_id del usuario
 * autenticado y lo inyecta automáticamente al crear registros.
 */
trait HasTenant
{
    protected static function bootHasTenant(): void
    {
        // Filtrar lecturas por empresa del usuario autenticado
        static::addGlobalScope('tenant', function (Builder $query) {
            $user = auth('api')->user();

            // Sin usuario autenticado (comandos artisan, jobs, seeders): sin
            // scope, esos contextos operan a propósito sobre todas las empresas.
            if (!$user) {
                return;
            }

            if ($user->empresa_id) {
                $query->where($query->getModel()->getTable() . '.empresa_id', $user->empresa_id);
                return;
            }

            // Usuario autenticado sin empresa (típicamente is_super_admin fuera
            // de una impersonación): no debe ver datos de ninguna empresa por
            // rutas normales. Sin este corte, si alguna vez tuviera permisos
            // directos asignados, estas queries devolverían filas de TODAS las
            // empresas sin filtrar en vez de ninguna.
            $query->whereRaw('1 = 0');
        });

        // Inyectar empresa_id automáticamente al crear
        static::creating(function (Model $model) {
            if (empty($model->empresa_id)) {
                $user = auth('api')->user();
                if ($user && $user->empresa_id) {
                    $model->empresa_id = $user->empresa_id;
                }
            }
        });
    }

    public function empresa()
    {
        return $this->belongsTo(\App\Models\Empresa::class, 'empresa_id');
    }
}
