<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Empresa;
use Illuminate\Http\JsonResponse;

trait ChecksPlanLimits
{
    /**
     * Bloquea la fila de la empresa (SELECT ... FOR UPDATE) para serializar
     * altas concurrentes del mismo recurso — sin esto, dos requests que crean
     * a la vez podían contar y pasar el chequeo de límite las dos antes de
     * que la primera terminara de confirmar la suya. Debe llamarse dentro de
     * una transacción abierta (DB::beginTransaction() / DB::transaction()).
     */
    protected function lockEmpresa(Empresa $empresa): Empresa
    {
        return Empresa::whereKey($empresa->id)->lockForUpdate()->first();
    }

    /**
     * Devuelve una respuesta 403 si la empresa ya alcanzó el límite de su
     * plan para el recurso indicado ('productos', 'usuarios' o 'sucursales'),
     * o null si todavía tiene lugar para crear uno más.
     */
    protected function limitePlanExcedido(Empresa $empresa, string $recurso, int $cantidadActual): ?JsonResponse
    {
        $limites = config('planes.limites')[$empresa->plan] ?? config('planes.limites')['free'];
        $limite  = $limites[$recurso] ?? null;

        if ($limite !== null && $cantidadActual >= $limite) {
            return response()->json([
                'success' => false,
                'message' => "Alcanzaste el límite de {$limite} {$recurso} de tu plan actual. Actualizá tu plan para seguir agregando.",
            ], 403);
        }

        return null;
    }

    /**
     * ¿El plan de la empresa incluye esta función ('catalogo' o 'ia' — ver
     * config/planes.php)? Para chequeos de solo lectura (armar una respuesta
     * informativa); para bloquear una acción usá funcionNoDisponibleEnPlan.
     */
    protected function planTieneFuncion(Empresa $empresa, string $feature): bool
    {
        $features = config('planes.features')[$empresa->plan] ?? config('planes.features')['free'];
        return !empty($features[$feature]);
    }

    /**
     * Devuelve una respuesta 403 si el plan de la empresa no incluye la
     * función indicada, o null si sí la tiene disponible.
     */
    protected function funcionNoDisponibleEnPlan(Empresa $empresa, string $feature, string $mensaje): ?JsonResponse
    {
        if ($this->planTieneFuncion($empresa, $feature)) {
            return null;
        }

        return response()->json([
            'success'        => false,
            'message'        => $mensaje,
            'plan_requerido' => true,
        ], 403);
    }
}
