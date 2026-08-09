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
            // Línea de "monto libre" (sin id_producto, ver handleAgregarMonto en
            // Home.jsx): no hay precio de lista contra el cual comparar, pero es
            // el mismo vector de fraude que un precio manipulado — un producto
            // real se puede entregar y cobrar como un monto suelto sin dejar
            // ningún rastro de qué salió de verdad. Requiere el mismo permiso.
            if (empty($linea['id_producto'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenés permiso para cargar un monto libre',
                ], 403);
            }

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

    /**
     * A diferencia de precioLineasSinPermiso() de arriba, esto NO se salta
     * aunque el usuario tenga permiso — sirve para saber si hay que exigir
     * un motivo (ver VentasController::store()), no para bloquear nada. Un
     * cajero autorizado a descontar igual tiene que dejar por escrito POR
     * QUÉ lo hizo en cada venta puntual, no alcanza con tener el permiso.
     */
    protected function hayDescuentoEnLineas(array $lineas, int $empresaId): bool
    {
        $ids = collect($lineas)->pluck('id_producto')->filter()->unique();
        $precios = Producto::where('empresa_id', $empresaId)->whereIn('id', $ids)->pluck('precio', 'id');

        foreach ($lineas as $linea) {
            if (empty($linea['id_producto'])) return true; // monto libre

            $precioReal   = (float) ($precios[$linea['id_producto']] ?? 0);
            $precioPedido = (float) ($linea['precio_venta'] ?? 0);
            if (abs($precioPedido - $precioReal) > 0.01) return true;
        }

        return false;
    }
}
