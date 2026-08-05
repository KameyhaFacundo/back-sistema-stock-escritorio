<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPlanLimits;
use App\Models\Empresa;
use App\Models\Producto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogoController extends Controller
{
    use ChecksPlanLimits;

    /**
     * GET /empresa/catalogo — estado actual del catálogo de la empresa
     * autenticada: si está activo, cuál es su link público, y si el plan
     * actual incluye esta función (el front usa esto último para mostrar
     * un cartel de upgrade en vez del switch cuando no la tiene).
     */
    public function config(): JsonResponse
    {
        $empresa = auth('api')->user()->empresa;
        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada'], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'activo'             => $empresa->catalogo_activo,
                'slug'               => $empresa->catalogo_slug,
                'disponible_en_plan' => $this->planTieneFuncion($empresa, 'catalogo'),
                'url'    => $empresa->catalogo_slug
                    ? rtrim(config('app.frontend_url', 'http://localhost:5173'), '/') . '/catalogo/' . $empresa->catalogo_slug
                    : null,
            ],
        ]);
    }

    /**
     * PUT /empresa/catalogo — activa/desactiva el catálogo público. La
     * primera vez que se activa, genera el slug (queda fijo después, así el
     * link que ya haya compartido el dueño no se rompe si vuelve a
     * desactivar/activar). Desactivarlo siempre está permitido, aunque el
     * plan ya no lo incluya — solo se bloquea intentar ACTIVARLO sin plan.
     */
    public function actualizar(Request $request): JsonResponse
    {
        $request->validate(['activo' => 'required|boolean']);

        $empresa = auth('api')->user()->empresa;
        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada'], 400);
        }

        if ($request->activo) {
            $resp = $this->funcionNoDisponibleEnPlan(
                $empresa, 'catalogo',
                'El catálogo online está disponible desde el plan Pro. Actualizá tu plan para activarlo.'
            );
            if ($resp) return $resp;

            if (!$empresa->catalogo_slug) {
                $empresa->asegurarCatalogoSlug();
            }
        }

        $empresa->update(['catalogo_activo' => $request->activo]);

        return $this->config();
    }

    /**
     * GET /catalogo/{slug} — página pública (sin login) con los productos
     * activos de la empresa. No usa el scope de tenant (no hay usuario
     * autenticado en este request) — el filtro por empresa_id es explícito.
     */
    public function show(string $slug): JsonResponse
    {
        $empresa = Empresa::where('catalogo_slug', $slug)->where('catalogo_activo', true)->first();
        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'Catálogo no encontrado'], 404);
        }

        // Revalidar el plan acá y no solo al activar: si la empresa bajó de
        // plan después de activarlo, catalogo_activo sigue en true (nada lo
        // resetea automáticamente) y el link público quedaría sirviendo
        // igual sin que el plan actual lo incluya.
        if (!$this->planTieneFuncion($empresa, 'catalogo')) {
            return response()->json(['success' => false, 'message' => 'Catálogo no encontrado'], 404);
        }

        // whereNull('id_producto_padre'): una variante (talle) no aparece como su
        // propia fila acá — un cliente viendo el catálogo elige el talle desde el
        // tile del producto madre, no ve "Remera básica" repetida una vez por talle.
        $productos = Producto::where('empresa_id', $empresa->id)
            ->where('estado', true)
            ->whereNull('id_producto_padre')
            ->with([
                'categoria', 'componentes.producto', 'talle',
                'variantes' => fn ($q) => $q->where('estado', true)->with('talle')->withSum('stocks', 'stock'),
            ])
            ->withSum('stocks', 'stock')
            ->orderBy('producto')
            ->get()
            ->map(function ($p) {
                $variantes = $p->tiene_variantes
                    ? $p->variantes
                        ->map(fn ($v) => [
                            'id'         => $v->id,
                            'talle'      => $v->talle?->valor ?? '',
                            'orden'      => $v->talle?->orden ?? 0,
                            'stock'      => (float) ($v->stocks_sum_stock ?? 0),
                            'disponible' => ($v->stocks_sum_stock ?? 0) > 0,
                        ])
                        ->sortBy('orden')
                        ->values()
                    : collect();

                $disponible = $p->es_combo
                    ? true
                    : ($p->tiene_variantes
                        ? $variantes->contains(fn ($v) => $v['disponible'])
                        : (($p->stocks_sum_stock ?? 0) > 0));

                return [
                    'id'             => $p->id,
                    'nombre'         => $p->producto,
                    'categoria'      => $p->categoria?->categoria ?? 'Sin categoría',
                    'precio'         => (float) $p->precio,
                    'esCombo'        => (bool) $p->es_combo,
                    'tieneVariantes' => (bool) $p->tiene_variantes,
                    // Solo relevante para un producto SIN variantes (talle fijo y
                    // propio) — uno con variantes ya expone el talle de cada una
                    // en 'variantes' de arriba.
                    'talle'          => !$p->tiene_variantes ? ($p->talle?->valor ?? null) : null,
                    'imagenUrl'      => $p->imagen_url,
                    'disponible'     => $disponible,
                    'componentes'    => $p->es_combo
                        ? $p->componentes->map(fn ($c) => [
                            'nombre'   => $c->producto?->producto ?? '—',
                            'cantidad' => (int) $c->cantidad,
                        ])->values()
                        : [],
                    'variantes' => $variantes,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'empresa' => [
                    'nombre'   => $empresa->nombre,
                    'logo_url' => $empresa->logo_url,
                    'whatsapp' => $empresa->whatsapp,
                ],
                'productos' => $productos,
            ],
        ]);
    }
}
