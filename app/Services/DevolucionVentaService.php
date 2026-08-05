<?php

namespace App\Services;

use App\Models\DevolucionVenta;
use App\Models\DevolucionVentaLinea;
use App\Models\Producto;
use App\Models\Venta;

/**
 * Devolución PARCIAL de una venta ya confirmada — a diferencia de
 * VentasController::anular() (todo o nada, transición de estado única),
 * esta clase puede llamarse varias veces sobre la misma venta, devolviendo
 * distintas líneas/cantidades cada vez. Reutiliza la misma lógica de
 * reposición de stock y ajuste de caja que ya usa anular(), generalizada a
 * cantidades parciales por línea.
 */
class DevolucionVentaService
{
    public function __construct(
        private StockService $stockService,
        private TurnoService $turnoService,
    ) {}

    /**
     * @param array<array{id_linea_venta:int, cantidad:float}> $lineasData
     */
    public function crear(int $idVenta, array $lineasData, ?string $motivo): DevolucionVenta
    {
        // lockForUpdate: dos devoluciones parciales concurrentes sobre la misma
        // venta no deben poder devolver de más la misma línea (ver el chequeo
        // de "ya devuelta" más abajo, que lee de la misma tabla que esto protege).
        $venta = Venta::where('id', $idVenta)->lockForUpdate()->with('lineas')->firstOrFail();

        if ($venta->estado === 'cancelada') {
            throw new \RuntimeException('Esta venta ya está anulada');
        }

        $lineasPorId = $venta->lineas->keyBy('id_linea');

        // Suma cantidades repetidas del mismo id_linea_venta en un mismo pedido
        // ANTES de validar disponibilidad — si no, dos entradas de la misma
        // línea en el mismo request verían el mismo "disponible" cada una por
        // separado y podrían sumar más de lo que hay.
        $pedidoPorLinea = [];
        foreach ($lineasData as $item) {
            $id = (int) $item['id_linea_venta'];
            $pedidoPorLinea[$id] = ($pedidoPorLinea[$id] ?? 0) + (float) $item['cantidad'];
        }

        $itemsValidados = [];
        foreach ($pedidoPorLinea as $idLineaVenta => $cantidadPedida) {
            $linea = $lineasPorId->get($idLineaVenta);
            if (!$linea) {
                throw new \RuntimeException('Una de las líneas no pertenece a esta venta');
            }

            $yaDevuelta = (float) DevolucionVentaLinea::where('id_linea_venta', $linea->id_linea)->sum('cantidad');
            $disponible = (float) $linea->cantidad - $yaDevuelta;

            // Tolerancia chica por redondeo de decimales (unidad_medida fraccionable).
            if ($cantidadPedida > $disponible + 0.001) {
                throw new \RuntimeException("'{$linea->nombre}' ya tiene {$yaDevuelta} devuelto de {$linea->cantidad} — no se puede devolver {$cantidadPedida}");
            }

            $itemsValidados[] = ['linea' => $linea, 'cantidad' => $cantidadPedida];
        }

        // Reponer stock — expandir líneas de combo a sus componentes, igual que
        // VentasController::anular() y VentaCreacionService al descontar. Una
        // línea de una variante por talle ya apunta al id de esa variante
        // puntual, así que no necesita ningún caso especial acá.
        if ($venta->id_sucursal) {
            $idsProductos = collect($itemsValidados)->pluck('linea.id_producto')->filter()->unique();
            $productosPorId = Producto::whereIn('id', $idsProductos)->with('componentes')->get()->keyBy('id');

            $demandaBase = [];
            foreach ($itemsValidados as $item) {
                $linea = $item['linea'];
                if (!$linea->id_producto) {
                    continue;
                }
                $producto = $productosPorId[$linea->id_producto] ?? null;
                if (!$producto) {
                    continue;
                }

                if ($producto->es_combo) {
                    foreach ($producto->componentes as $comp) {
                        $demandaBase[$comp->id_producto] = ($demandaBase[$comp->id_producto] ?? 0) + $comp->cantidad * $item['cantidad'];
                    }
                } else {
                    $demandaBase[$producto->id] = ($demandaBase[$producto->id] ?? 0) + $item['cantidad'];
                }
            }

            foreach ($demandaBase as $idProducto => $cantidad) {
                $this->stockService->ajustar($idProducto, $venta->id_sucursal, $cantidad, $venta->empresa_id);
            }
        }

        $montoDevuelto = collect($itemsValidados)->sum(fn($item) => (float) $item['linea']->precio_venta * $item['cantidad']);

        // Si el cliente ya había pagado más de lo que le queda por deber
        // después de esta devolución, la diferencia se le devuelve en
        // efectivo real — no alcanza con solo bajarle la deuda.
        $saldoPendienteAntes  = max(0, (float) $venta->monto_total - (float) $venta->monto_cobrado);
        $efectivoADevolver    = max(0, $montoDevuelto - $saldoPendienteAntes);
        $cajaAjustada         = true;

        if ($efectivoADevolver > 0) {
            // Siempre la caja ACTUALMENTE abierta, no la de la venta original
            // (a diferencia de anular(), que usa la del turno original para el
            // cobro pero la activa para los pagos de fiado — acá se unifica a
            // "turno activo" para los dos casos).
            $turnoActivo = $this->turnoService->activo(auth()->user()->nro_usu, auth()->user()->id_sucursal, lock: true);
            if ($turnoActivo) {
                $turnoActivo->efectivo_actual = max(0, $turnoActivo->efectivo_actual - $efectivoADevolver);
                $turnoActivo->ventas_efectivo = max(0, $turnoActivo->ventas_efectivo - $efectivoADevolver);
                $turnoActivo->save();
            } else {
                $cajaAjustada = false;
                $efectivoADevolver = 0;
            }
        }

        $venta->monto_total   = max(0, (float) $venta->monto_total - $montoDevuelto);
        $venta->monto_cobrado = max(0, (float) $venta->monto_cobrado - $efectivoADevolver);

        $devolucion = DevolucionVenta::create([
            'id_venta'                => $venta->id,
            'id_usuario'              => auth()->id(),
            'motivo'                  => $motivo,
            'monto_devuelto'          => $montoDevuelto,
            'monto_efectivo_devuelto' => $efectivoADevolver,
            'caja_ajustada'           => $cajaAjustada,
        ]);

        foreach ($itemsValidados as $item) {
            DevolucionVentaLinea::create([
                'id_devolucion_venta' => $devolucion->id,
                'id_linea_venta'      => $item['linea']->id_linea,
                'id_producto'         => $item['linea']->id_producto,
                'cantidad'            => $item['cantidad'],
                'precio_unitario'     => $item['linea']->precio_venta,
            ]);
        }

        // ¿Quedaron todas las líneas 100% devueltas (contando esta devolución
        // y las anteriores)? Si es así, converge con lo que ya hace anular().
        $totalmenteDevuelta = $venta->lineas->every(function ($linea) {
            $devuelto = (float) DevolucionVentaLinea::where('id_linea_venta', $linea->id_linea)->sum('cantidad');
            return $devuelto >= (float) $linea->cantidad - 0.001;
        });

        if ($totalmenteDevuelta) {
            $venta->estado       = 'cancelada';
            $venta->estado_pago  = 'pendiente';
        } elseif ((float) $venta->monto_total - (float) $venta->monto_cobrado <= 0.001) {
            $venta->estado_pago = 'pagado';
        } else {
            $venta->estado_pago = (float) $venta->monto_cobrado > 0 ? 'parcial' : 'pendiente';
        }

        $venta->save();

        return $devolucion->load('lineas');
    }
}
