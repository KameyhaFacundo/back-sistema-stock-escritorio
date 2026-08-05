<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateCompraRequest;
use App\Http\Requests\CreateDevolucionCompraRequest;
use App\Http\Requests\UpdateCompraRequest;
use App\Models\Compra;
use App\Models\HistorialPrecio;
use App\Models\LineaCompra;
use App\Models\MovimientoCaja;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\DevolucionCompraService;
use App\Services\StockService;
use App\Services\TurnoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComprasController extends Controller
{
    public function __construct(
        private StockService $stockService,
        private TurnoService $turnoService,
        private DevolucionCompraService $devolucionCompraService,
    ) {}

    /**
     * Auditoría de inventario para compras — mismo patrón que las ventas
     * (VentaCreacionService). $cantidad va con signo: positivo cuando el
     * stock sube (compra confirmada o revertida a confirmada), negativo
     * cuando baja (se revierte una compra que estaba confirmada).
     */
    private function registrarMovimientoCompra(Producto $producto, float $cantidad, int $idCompra, int $idSucursal): void
    {
        if ($cantidad == 0.0) return;

        MovimientoStock::create([
            'empresa_id'  => $producto->empresa_id,
            'id_sucursal' => $idSucursal,
            'id_producto' => $producto->id,
            'id_usuario'  => auth()->user()?->nro_usu,
            'producto'    => $producto->producto,
            'codigo'      => $producto->codigo,
            'tipo'        => 'compra',
            'sub_tipo'    => "Compra #{$idCompra}",
            'cantidad'    => $cantidad,
            'fecha'       => now()->format('Y-m-d'),
            'hora'        => now()->format('H:i'),
        ]);
    }

    /**
     * Aplica el precio de venta de una línea de compra al producto y deja
     * registro en HistorialPrecio — misma lógica que ProductosController::update()
     * usa para un cambio de precio manual, para que el historial sea uno solo
     * sin importar si el precio cambió desde Productos o desde una Compra.
     */
    private function actualizarPrecioVenta(Producto $producto, $precioVenta): void
    {
        if (empty($precioVenta)) return;

        $precioAnterior = (float) $producto->precio;
        $precioNuevo    = (float) $precioVenta;
        if ($precioNuevo === $precioAnterior) return;

        $producto->update(['precio' => $precioNuevo]);

        HistorialPrecio::create([
            'empresa_id'      => $producto->empresa_id,
            'id_producto'     => $producto->id,
            'campo'           => 'precio',
            'precio_anterior' => $precioAnterior,
            'precio_nuevo'    => $precioNuevo,
            'id_usuario'      => auth()->id(),
        ]);
    }

    /**
     * Ajusta el efectivo del turno abierto por una compra en efectivo, con el
     * mismo gate que el stock (solo se mueve caja cuando la compra pasa a
     * "confirmada", y se revierte cuando sale de "confirmada"/se borra) —
     * antes esto restaba efectivo_actual directamente en store() sin importar
     * el estado, sin dejar ningún renglón en Caja > Movimientos, y nunca se
     * revertía al editar/cancelar/borrar la compra.
     *
     * $monto negativo = egreso (se gastó plata real), positivo = ingreso
     * (se revierte una compra que ya había restado efectivo).
     */
    private function ajustarCajaCompra(Compra $compra, int $idSucursal, float $monto, string $motivoPrefijo): void
    {
        if ($compra->metodo_pago !== 'efectivo' || $monto == 0.0) return;

        // lockForUpdate: mismo motivo que en VentaCreacionService/CajaController —
        // efectivo_actual se lee y se vuelve a escribir más abajo, sin lock una
        // venta/compra/movimiento concurrente sobre el mismo turno podía perderse.
        $turnoActivo = $this->turnoService->activo(auth()->user()->nro_usu, $idSucursal, lock: true);
        if (!$turnoActivo) return;

        MovimientoCaja::create([
            'id_turno' => $turnoActivo->id,
            'tipo'     => $monto < 0 ? 'egreso' : 'ingreso',
            'monto'    => abs($monto),
            'motivo'   => "{$motivoPrefijo} #{$compra->id}",
            'hora'     => now()->format('H:i'),
        ]);

        $turnoActivo->efectivo_actual = max(0, $turnoActivo->efectivo_actual + $monto);
        $turnoActivo->save();
    }

    public function index(Request $request): JsonResponse
    {
        $query = Compra::with(['proveedor', 'usuario', 'usuarioAnulacion', 'lineas.producto.categoria', 'devoluciones.lineas']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('proveedor', fn($q) => $q->where('persona', 'like', "%{$search}%"));
        }

        if ($request->filled('id_proveedor')) {
            $query->where('id_proveedor', $request->id_proveedor);
        }

        if ($request->filled('id_sucursal')) {
            $query->where('id_sucursal', $request->id_sucursal);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('fecha_desde')) {
            $query->where('fecha', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->where('fecha', '<=', $request->fecha_hasta);
        }

        // Mismo desempate que VentasController::index() — orderBy('fecha') solo
        // ordena por día, dos compras del mismo día quedaban en un orden
        // indefinido entre sí.
        $compras = $query->orderBy('fecha', 'desc')->orderBy('id', 'desc')->paginate($request->per_page ?? 15);

        return response()->json(['success' => true, 'data' => $compras]);
    }

    public function show($id): JsonResponse
    {
        $compra = Compra::with(['proveedor', 'usuario', 'usuarioAnulacion', 'lineas.producto.categoria', 'devoluciones.lineas'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $compra]);
    }

    public function store(CreateCompraRequest $request): JsonResponse
    {
        $idSucursal = auth()->user()->id_sucursal;
        if (!$idSucursal) {
            return response()->json(['success' => false, 'message' => 'Tu usuario no tiene una sucursal asignada'], 422);
        }

        DB::beginTransaction();

        try {
            $proveedor = Proveedor::findOrFail($request->id_proveedor);
            if (!$proveedor->estado) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'El proveedor no está activo'], 400);
            }

            $ids = collect($request->lineas)->pluck('id_producto')->unique();
            $productosMap = Producto::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            foreach ($request->lineas as $linea) {
                $producto = $productosMap[$linea['id_producto']] ?? null;
                if (!$producto) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Producto no encontrado'], 404);
                }
                if (!$producto->estado) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => "El producto '{$producto->producto}' no está activo"], 400);
                }
                // Un combo no tiene stock propio (se calcula de sus componentes, ver
                // Producto::stockDisponibleEnSucursal) — comprarle stock directo a uno
                // quedaría cargado en producto_stock pero invisible para siempre.
                if ($producto->es_combo) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => "'{$producto->producto}' es un combo y no puede comprarse directamente — comprá sus componentes por separado"], 400);
                }
                // Un producto madre con variantes no tiene stock propio (vive en
                // cada variante) — se compra la variante puntual (talle).
                if ($producto->tiene_variantes) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => "'{$producto->producto}' tiene variantes — comprá el talle puntual, no el producto general"], 400);
                }
            }

            $montoTotal  = collect($request->lineas)->sum(fn($l) => $l['precio_compra'] * $l['cantidad']);
            $metodoPago  = $request->metodo_pago ?? 'efectivo';
            $esCredito   = $metodoPago === 'cuenta_corriente';
            $estadoDeuda = $esCredito ? 'pendiente' : 'pagado';
            $montoPagado = $esCredito ? 0 : $montoTotal;
            $estado      = $request->estado ?? 'confirmada';

            $compra = Compra::create([
                'id_proveedor' => $request->id_proveedor,
                'id_usuario'   => auth()->user()->nro_usu,
                'id_sucursal'  => $idSucursal,
                'estado'       => $estado,
                'fecha'        => substr($request->fecha, 0, 10),
                'metodo_pago'  => $metodoPago,
                'estado_deuda' => $estadoDeuda,
                'monto_pagado' => $montoPagado,
                'monto_total'  => $montoTotal,
                'cuit'         => $proveedor->cuit,
            ]);

            foreach ($request->lineas as $linea) {
                LineaCompra::create([
                    'id_compra'    => $compra->id,
                    'id_producto'  => $linea['id_producto'],
                    'precio_compra'=> $linea['precio_compra'],
                    'precio_venta' => $linea['precio_venta'] ?? null,
                    'cantidad'     => $linea['cantidad'],
                ]);
                // Solo actualizar stock y precio de venta si la compra fue confirmada
                if ($estado === 'confirmada') {
                    $producto = $productosMap[$linea['id_producto']];
                    $this->stockService->ajustar($producto->id, $idSucursal, $linea['cantidad'], $producto->empresa_id);
                    $this->actualizarPrecioVenta($producto, $linea['precio_venta'] ?? null);
                    $this->registrarMovimientoCompra($producto, $linea['cantidad'], $compra->id, $idSucursal);
                }
            }

            if ($estado === 'confirmada') {
                $this->ajustarCajaCompra($compra, $idSucursal, -$montoTotal, 'Compra');
            }

            DB::commit();

            $compra->load(['proveedor', 'usuario', 'usuarioAnulacion', 'lineas.producto.categoria', 'devoluciones.lineas']);

            return response()->json([
                'success' => true,
                'message' => 'Compra creada correctamente',
                'data'    => $compra,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al crear la compra', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(UpdateCompraRequest $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $compra = Compra::with('lineas')->findOrFail($id);
            $estadoAnterior = $compra->estado;

            // Una compra confirmada ya sumó stock y (si fue en efectivo) movió caja.
            // Editarla reabre la ventana de "revertir viejo + aplicar nuevo" sin poder
            // saber si ese stock ya se vendió entre medio, así que una vez confirmada
            // queda bloqueada por completo (chequeo replicado del front en Compras.jsx).
            if ($estadoAnterior === 'confirmada') {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Esta compra ya está confirmada y no se puede editar'], 422);
            }

            // El stock se ajusta siempre en la sucursal original de la compra, no en
            // la sucursal activa de quien la edita — así no se mueve mercadería de
            // local sin querer solo por editar una compra vieja desde otra sucursal.
            $idSucursal = $compra->id_sucursal ?? auth()->user()->id_sucursal;
            if (!$idSucursal) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Esta compra no tiene una sucursal asignada'], 422);
            }

            if ($request->has('id_proveedor')) {
                $proveedor = Proveedor::findOrFail($request->id_proveedor);
                if (!$proveedor->estado) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'El proveedor no está activo'], 400);
                }
                $compra->id_proveedor = $request->id_proveedor;
                $compra->cuit         = $proveedor->cuit;
            }

            if ($request->has('lineas')) {
                $ids = collect($request->lineas)->pluck('id_producto')->unique();
                $productosMap = Producto::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

                foreach ($request->lineas as $linea) {
                    $producto = $productosMap[$linea['id_producto']] ?? null;
                    if ($producto && !$producto->estado) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => "El producto '{$producto->producto}' no está activo"], 400);
                    }
                    if ($producto && $producto->es_combo) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => "'{$producto->producto}' es un combo y no puede comprarse directamente — comprá sus componentes por separado"], 400);
                    }
                    if ($producto && $producto->tiene_variantes) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => "'{$producto->producto}' tiene variantes — comprá el talle puntual, no el producto general"], 400);
                    }
                }

                // Revertir stock (y caja, si correspondía) de las líneas anteriores si
                // la compra estaba confirmada
                if ($estadoAnterior === 'confirmada') {
                    $oldIds     = $compra->lineas->pluck('id_producto')->unique();
                    $oldProdMap = Producto::whereIn('id', $oldIds)->lockForUpdate()->get()->keyBy('id');
                    foreach ($compra->lineas as $linea) {
                        $prod = $oldProdMap[$linea->id_producto] ?? null;
                        if ($prod) {
                            $this->stockService->ajustar($prod->id, $idSucursal, -$linea->cantidad, $prod->empresa_id);
                            $this->registrarMovimientoCompra($prod, -$linea->cantidad, $compra->id, $idSucursal);
                        }
                    }
                    $this->ajustarCajaCompra($compra, $idSucursal, (float) $compra->monto_total, 'Reversión de compra');
                }

                $compra->lineas()->delete();

                $estadoNuevo = $request->has('estado') ? $request->estado : $estadoAnterior;
                $montoTotal  = 0;
                foreach ($request->lineas as $linea) {
                    $montoTotal += $linea['precio_compra'] * $linea['cantidad'];
                    LineaCompra::create([
                        'id_compra'    => $compra->id,
                        'id_producto'  => $linea['id_producto'],
                        'precio_compra'=> $linea['precio_compra'],
                        'precio_venta' => $linea['precio_venta'] ?? null,
                        'cantidad'     => $linea['cantidad'],
                    ]);
                    // Aplicar stock y precio de venta solo si la compra quedará confirmada
                    if ($estadoNuevo === 'confirmada') {
                        $producto = $productosMap[$linea['id_producto']];
                    $this->stockService->agregar(
                        $producto->id,
                        $idSucursal,
                        $linea['cantidad'],
                        $producto->empresa_id,
                        $linea['fecha_vencimiento'] ?? null,
                        $compra->id,
                        auth()->user()?->nro_usu
                    );
                        $this->actualizarPrecioVenta($producto, $linea['precio_venta'] ?? null);
                        $this->registrarMovimientoCompra($producto, $linea['cantidad'], $compra->id, $idSucursal);
                    }
                }
                $compra->monto_total = $montoTotal;
                if ($estadoNuevo === 'confirmada') {
                    $this->ajustarCajaCompra($compra, $idSucursal, -$montoTotal, 'Compra');
                }

            } elseif ($request->has('estado') && $request->estado !== $estadoAnterior) {
                // Solo cambió el estado (sin tocar líneas): ajustar stock y caja según transición
                $estadoNuevo = $request->estado;
                $ids     = $compra->lineas->pluck('id_producto')->unique();
                $prodMap = Producto::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
                foreach ($compra->lineas as $linea) {
                    $prod = $prodMap[$linea->id_producto] ?? null;
                    if (!$prod) continue;
                    if ($estadoNuevo === 'confirmada') {
                        $this->stockService->ajustar($prod->id, $idSucursal, $linea->cantidad, $prod->empresa_id);
                        $this->registrarMovimientoCompra($prod, $linea->cantidad, $compra->id, $idSucursal);
                    } elseif ($estadoAnterior === 'confirmada') {
                        $this->stockService->ajustar($prod->id, $idSucursal, -$linea->cantidad, $prod->empresa_id);
                        $this->registrarMovimientoCompra($prod, -$linea->cantidad, $compra->id, $idSucursal);
                    }
                }
                if ($estadoNuevo === 'confirmada') {
                    $this->ajustarCajaCompra($compra, $idSucursal, -(float) $compra->monto_total, 'Compra');
                } elseif ($estadoAnterior === 'confirmada') {
                    $this->ajustarCajaCompra($compra, $idSucursal, (float) $compra->monto_total, 'Reversión de compra');
                }
            }

            if ($request->has('fecha'))  { $compra->fecha  = $request->fecha; }
            if ($request->has('estado')) { $compra->estado = $request->estado; }

            $compra->save();
            DB::commit();

            $compra->load(['proveedor', 'usuario', 'usuarioAnulacion', 'lineas.producto.categoria', 'devoluciones.lineas']);

            return response()->json(['success' => true, 'message' => 'Compra actualizada correctamente', 'data' => $compra]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al actualizar la compra', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $compra = Compra::with('lineas')->findOrFail($id);
            $idSucursal = $compra->id_sucursal ?? auth()->user()->id_sucursal;

            // Revertir stock y caja si la compra estaba confirmada (protegido contra negativo)
            if ($compra->estado === 'confirmada' && $idSucursal) {
                $ids     = $compra->lineas->pluck('id_producto')->unique();
                $prodMap = Producto::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
                foreach ($compra->lineas as $linea) {
                    $prod = $prodMap[$linea->id_producto] ?? null;
                    if ($prod) {
                        $this->stockService->ajustar($prod->id, $idSucursal, -$linea->cantidad, $prod->empresa_id);
                        $this->registrarMovimientoCompra($prod, -$linea->cantidad, $compra->id, $idSucursal);
                    }
                }
                $this->ajustarCajaCompra($compra, $idSucursal, (float) $compra->monto_total, 'Reversión de compra (eliminada)');
            }

            $compra->delete();
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Compra eliminada correctamente']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al eliminar la compra', 'error' => $e->getMessage()], 500);
        }
    }

    public function changeStatus(Request $request, $id): JsonResponse
    {
        $request->validate(['estado' => 'required|in:pendiente,confirmada,cancelada']);

        DB::beginTransaction();
        try {
            $compra = Compra::with('lineas')->findOrFail($id);
            $estadoAnterior = $compra->estado;
            $estadoNuevo    = $request->estado;
            $idSucursal     = $compra->id_sucursal ?? auth()->user()->id_sucursal;

            if ($estadoNuevo !== $estadoAnterior && $idSucursal) {
                $ids     = $compra->lineas->pluck('id_producto')->unique();
                $prodMap = Producto::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
                foreach ($compra->lineas as $linea) {
                    $prod = $prodMap[$linea->id_producto] ?? null;
                    if (!$prod) continue;
                    if ($estadoNuevo === 'confirmada') {
                        $this->stockService->ajustar($prod->id, $idSucursal, $linea->cantidad, $prod->empresa_id);
                        // A diferencia de store()/update(), acá no viene un precio_venta
                        // nuevo en el request — se confirma la compra tal cual se cargó,
                        // así que el precio a aplicar es el que ya quedó guardado en la
                        // línea (antes esta rama no tocaba el precio para nada: confirmar
                        // una compra "pendiente" nunca actualizaba el precio de venta).
                        $this->actualizarPrecioVenta($prod, $linea->precio_venta);
                        $this->registrarMovimientoCompra($prod, $linea->cantidad, $compra->id, $idSucursal);
                    } elseif ($estadoAnterior === 'confirmada') {
                        $this->stockService->ajustar($prod->id, $idSucursal, -$linea->cantidad, $prod->empresa_id);
                        $this->registrarMovimientoCompra($prod, -$linea->cantidad, $compra->id, $idSucursal);
                    }
                }
                if ($estadoNuevo === 'confirmada') {
                    $this->ajustarCajaCompra($compra, $idSucursal, -(float) $compra->monto_total, 'Compra');
                } elseif ($estadoAnterior === 'confirmada') {
                    $this->ajustarCajaCompra($compra, $idSucursal, (float) $compra->monto_total, 'Reversión de compra');
                }
            }

            $compra->estado = $estadoNuevo;
            // Quién y cuándo anuló la compra — antes no quedaba registro de esto,
            // solo se veía el estado final "cancelada" sin saber quién lo hizo.
            if ($estadoNuevo === 'cancelada') {
                $compra->id_usuario_anulacion = auth()->user()->nro_usu;
                $compra->fecha_anulacion      = now();
            } elseif ($estadoAnterior === 'cancelada') {
                $compra->id_usuario_anulacion = null;
                $compra->fecha_anulacion      = null;
            }
            $compra->save();
            DB::commit();

            $compra->load(['proveedor', 'usuario', 'usuarioAnulacion', 'lineas.producto.categoria', 'devoluciones.lineas']);

            return response()->json(['success' => true, 'message' => 'Estado actualizado', 'data' => $compra]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al cambiar el estado', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Devuelve mercadería al proveedor: una o varias líneas, cada una con su
     * propia cantidad — a diferencia de cambiar el estado a "cancelada", que
     * es todo o nada. Se puede llamar más de una vez sobre la misma compra.
     */
    public function devolucion(CreateDevolucionCompraRequest $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $devolucion = $this->devolucionCompraService->crear(
                (int) $id,
                $request->validated()['lineas'],
                $request->validated()['motivo'] ?? null,
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $devolucion->caja_ajustada
                    ? 'Devolución registrada: se descontó el stock y se ajustó la caja'
                    : 'Devolución registrada y stock descontado, pero no se pudo ajustar la caja (no hay un turno abierto)',
                'data' => $devolucion,
            ], 201);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al registrar la devolución', 'error' => $e->getMessage()], 500);
        }
    }
}
