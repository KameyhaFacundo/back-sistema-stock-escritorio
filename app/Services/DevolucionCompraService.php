<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\DevolucionCompra;
use App\Models\DevolucionCompraLinea;
use App\Models\MovimientoCaja;
use App\Models\MovimientoStock;
use App\Models\Producto;

/**
 * Devolución PARCIAL de una compra ya confirmada — le devolvés mercadería al
 * proveedor. Es el espejo de DevolucionVentaService: en vez de reponer stock
 * y sacar plata de la caja, ACÁ se saca stock y (si corresponde) vuelve
 * plata a la caja. Se puede llamar más de una vez sobre la misma compra.
 *
 * A diferencia de ventas, una compra nunca puede tener líneas de combo ni de
 * un producto con variantes-madre (bloqueado desde ComprasController::store()/
 * update()) — cada línea ya es un producto o variante puntual con stock
 * propio, así que no hace falta ninguna expansión de demanda.
 */
class DevolucionCompraService
{
    public function __construct(
        private StockService $stockService,
        private TurnoService $turnoService,
    ) {}

    /**
     * @param array<array{id_linea_compra:int, cantidad:float}> $lineasData
     */
    public function crear(int $idCompra, array $lineasData, ?string $motivo): DevolucionCompra
    {
        // lockForUpdate: mismo motivo que DevolucionVentaService — dos
        // devoluciones concurrentes sobre la misma compra no deben poder
        // devolver de más la misma línea.
        $compra = Compra::where('id', $idCompra)->lockForUpdate()->with('lineas')->firstOrFail();

        if ($compra->estado !== 'confirmada') {
            throw new \RuntimeException('Solo se puede devolver mercadería de una compra confirmada');
        }

        $lineasPorId = $compra->lineas->keyBy('id_linea');

        // Suma cantidades repetidas del mismo id_linea_compra en un mismo
        // pedido antes de validar disponibilidad (ver mismo criterio en
        // DevolucionVentaService).
        $pedidoPorLinea = [];
        foreach ($lineasData as $item) {
            $id = (int) $item['id_linea_compra'];
            $pedidoPorLinea[$id] = ($pedidoPorLinea[$id] ?? 0) + (float) $item['cantidad'];
        }

        // Una sola consulta agrupada para todas las líneas pedidas, en vez de un
        // sum() por línea dentro del loop de validación de abajo.
        $devueltasPorLinea = DevolucionCompraLinea::whereIn('id_linea_compra', array_keys($pedidoPorLinea))
            ->selectRaw('id_linea_compra, SUM(cantidad) as total')
            ->groupBy('id_linea_compra')
            ->pluck('total', 'id_linea_compra');

        $itemsValidados = [];
        foreach ($pedidoPorLinea as $idLineaCompra => $cantidadPedida) {
            $linea = $lineasPorId->get($idLineaCompra);
            if (!$linea) {
                throw new \RuntimeException('Una de las líneas no pertenece a esta compra');
            }

            $yaDevuelta = (float) ($devueltasPorLinea[$idLineaCompra] ?? 0);
            $disponible = (float) $linea->cantidad - $yaDevuelta;

            if ($cantidadPedida > $disponible + 0.001) {
                throw new \RuntimeException("Esa línea ya tiene {$yaDevuelta} devuelto de {$linea->cantidad} — no se puede devolver {$cantidadPedida}");
            }

            $itemsValidados[] = ['linea' => $linea, 'cantidad' => $cantidadPedida];
        }

        // Sacar stock — StockService::ajustar() con delta negativo ya valida
        // que haya suficiente disponible (no se puede devolver más de lo que
        // físicamente queda, por si ya se vendió parte entre medio). Deja el
        // mismo rastro en Movimientos que ComprasController::registrarMovimientoCompra()
        // ya deja para cualquier otro cambio de stock originado en una compra.
        if ($compra->id_sucursal) {
            $productosPorId = Producto::whereIn('id', collect($itemsValidados)->pluck('linea.id_producto'))->get()->keyBy('id');
            foreach ($itemsValidados as $item) {
                $linea = $item['linea'];
                $this->stockService->ajustar($linea->id_producto, $compra->id_sucursal, -$item['cantidad'], $compra->empresa_id);

                $producto = $productosPorId[$linea->id_producto] ?? null;
                if ($producto) {
                    MovimientoStock::create([
                        'empresa_id'  => $compra->empresa_id,
                        'id_sucursal' => $compra->id_sucursal,
                        'id_producto' => $producto->id,
                        'id_usuario'  => auth()->id(),
                        'producto'    => $producto->producto,
                        'codigo'      => $producto->codigo,
                        'tipo'        => 'compra',
                        'sub_tipo'    => "Devolución de compra #{$compra->id}",
                        'cantidad'    => -$item['cantidad'],
                        'fecha'       => now()->format('Y-m-d'),
                        'hora'        => now()->format('H:i'),
                    ]);
                }
            }
        }

        $montoDevuelto = collect($itemsValidados)->sum(fn($item) => (float) $item['linea']->precio_compra * $item['cantidad']);

        // Si ya se había pagado más de lo que se le seguirá debiendo al
        // proveedor después de esta devolución, la diferencia vuelve en
        // efectivo real — no alcanza con solo bajarle la deuda.
        $saldoPendienteAntes = max(0, (float) $compra->monto_total - (float) $compra->monto_pagado);
        $efectivoADevolver   = max(0, $montoDevuelto - $saldoPendienteAntes);
        $cajaAjustada        = true;

        if ($compra->metodo_pago === 'efectivo' && $efectivoADevolver > 0) {
            // Siempre la caja ACTUALMENTE abierta — una compra ni siquiera
            // guarda a qué turno pertenece (a diferencia de una venta), así
            // que esto ya es la única opción posible, no una normalización.
            $turnoActivo = $this->turnoService->activo(auth()->user()->nro_usu, $compra->id_sucursal, lock: true);
            if ($turnoActivo) {
                MovimientoCaja::create([
                    'id_turno' => $turnoActivo->id,
                    'tipo'     => 'ingreso',
                    'monto'    => $efectivoADevolver,
                    'motivo'   => "Devolución de compra #{$compra->id}",
                    'hora'     => now()->format('H:i'),
                ]);
                $turnoActivo->efectivo_actual = max(0, $turnoActivo->efectivo_actual + $efectivoADevolver);
                $turnoActivo->save();
            } else {
                $cajaAjustada = false;
                $efectivoADevolver = 0;
            }
        }

        $compra->monto_total  = max(0, (float) $compra->monto_total - $montoDevuelto);
        $compra->monto_pagado = max(0, (float) $compra->monto_pagado - $efectivoADevolver);

        $devolucion = DevolucionCompra::create([
            'id_compra'               => $compra->id,
            'id_usuario'              => auth()->id(),
            'motivo'                  => $motivo,
            'monto_devuelto'          => $montoDevuelto,
            'monto_efectivo_devuelto' => $efectivoADevolver,
            'caja_ajustada'           => $cajaAjustada,
        ]);

        foreach ($itemsValidados as $item) {
            DevolucionCompraLinea::create([
                'id_devolucion_compra' => $devolucion->id,
                'id_linea_compra'      => $item['linea']->id_linea,
                'id_producto'          => $item['linea']->id_producto,
                'cantidad'             => $item['cantidad'],
                'precio_unitario'      => $item['linea']->precio_compra,
            ]);
        }

        // ¿Quedaron todas las líneas 100% devueltas? Converge con lo que ya
        // hace ComprasController::changeStatus() al cancelar. Una sola consulta
        // agrupada para todas las líneas de la compra (incluye las recién
        // creadas arriba), en vez de un sum() por línea.
        $devueltoPorLinea = DevolucionCompraLinea::whereIn('id_linea_compra', $compra->lineas->pluck('id_linea'))
            ->selectRaw('id_linea_compra, SUM(cantidad) as total')
            ->groupBy('id_linea_compra')
            ->pluck('total', 'id_linea_compra');
        $totalmenteDevuelta = $compra->lineas->every(function ($linea) use ($devueltoPorLinea) {
            $devuelto = (float) ($devueltoPorLinea[$linea->id_linea] ?? 0);
            return $devuelto >= (float) $linea->cantidad - 0.001;
        });

        if ($totalmenteDevuelta) {
            $compra->estado = 'cancelada';
            $compra->id_usuario_anulacion = auth()->id();
            $compra->fecha_anulacion = now();
            $compra->estado_deuda = 'pendiente';
        } elseif ((float) $compra->monto_total - (float) $compra->monto_pagado <= 0.001) {
            $compra->estado_deuda = 'pagado';
        } else {
            $compra->estado_deuda = (float) $compra->monto_pagado > 0 ? 'parcial' : 'pendiente';
        }

        $compra->save();

        return $devolucion->load('lineas');
    }
}
