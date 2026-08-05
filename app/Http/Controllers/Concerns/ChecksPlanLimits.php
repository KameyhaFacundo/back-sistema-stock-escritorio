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
     * Build local de un solo comercio, sin planes: nunca hay límite de
     * recursos que hacer cumplir. Se deja el método (en vez de sacarlo de los
     * controllers que lo llaman) para no tener que tocar esos 13 archivos.
     */
    protected function limitePlanExcedido(Empresa $empresa, string $recurso, int $cantidadActual): ?JsonResponse
    {
        return null;
    }

    /**
     * Build local de un solo comercio: todas las funciones están siempre
     * disponibles, no hay features atadas a un plan pago.
     */
    protected function planTieneFuncion(Empresa $empresa, string $feature): bool
    {
        return true;
    }

    /**
     * Ídem — nunca bloquea, ver planTieneFuncion().
     */
    protected function funcionNoDisponibleEnPlan(Empresa $empresa, string $feature, string $mensaje): ?JsonResponse
    {
        return null;
    }
}
