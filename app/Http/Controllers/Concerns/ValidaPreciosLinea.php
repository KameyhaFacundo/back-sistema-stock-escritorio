<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Producto;
use Illuminate\Http\JsonResponse;

trait ValidaPreciosLinea
{
    /**
     * lineas.*.precio_venta viene del cliente y solo se valida como numérico —
     * nada lo compara contra Producto::precio. Sin este chequeo, cualquier
     * usuario con create-ventas podía cobrar lo que quisiera por cualquier
     * producto (el input de precio del carrito en el POS no tiene ningún gate
     * de permiso: front-sistema-stock/src/pages/Home/Home.jsx, updatePrecio) —
     * un vector de fraude de caja directo (cobrar de menos y quedarse la
     * diferencia), no solo un bug de datos.
     *
     * Se trata como el mismo tipo de operación que el "ajuste" global de la
     * venta, que ya requiere aplicar-descuento-ventas: vender una línea por
     * arriba o por debajo del precio de lista es, funcionalmente, un
     * descuento/recargo manual.
     */
    protected function precioLineasSinPermiso(array $lineas, int $empresaId): ?JsonResponse
    {
        if (auth()->user()->chequearPermisos('aplicar-descuento-ventas')) {
            return null;
        }

        $ids = collect($lineas)->pluck('id_producto')->filter()->unique();
        $precios = Producto::where('empresa_id', $empresaId)->whereIn('id', $ids)->pluck('precio', 'id');

        foreach ($lineas as $linea) {
            // Línea de "monto libre" (sin id_producto): no hay precio de lista
            // contra el cual comparar, el monto es libre por diseño.
            if (empty($linea['id_producto'])) continue;

            $precioReal   = (float) ($precios[$linea['id_producto']] ?? 0);
            $precioPedido = (float) ($linea['precio_venta'] ?? 0);
            if (abs($precioPedido - $precioReal) > 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenés permiso para vender a un precio distinto del de lista',
                ], 403);
            }
        }

        return null;
    }
}
