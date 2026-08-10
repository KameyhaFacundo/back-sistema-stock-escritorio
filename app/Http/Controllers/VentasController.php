<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidaPreciosLinea;
use App\Http\Requests\CreateVentaRequest;
use App\Http\Requests\CreateDevolucionVentaRequest;
use App\Http\Requests\UpdateVentaRequest;
use App\Models\Venta;
use App\Models\DevolucionVentaLinea;
use App\Models\Presupuesto;
use App\Models\Producto;
use App\Models\Cliente;
use App\Models\Turno;
use App\Services\DevolucionVentaService;
use App\Services\StockService;
use App\Services\TurnoService;
use App\Services\VentaCreacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VentasController extends Controller
{
    use ValidaPreciosLinea;

    public function __construct(
        private VentaCreacionService $ventaCreacionService,
        private StockService $stockService,
        private TurnoService $turnoService,
        private DevolucionVentaService $devolucionVentaService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Venta::with(['cliente', 'usuario', 'lineas.producto.categoria', 'pagos', 'devoluciones.lineas', 'factura']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('cliente', fn($q) => $q->where('persona', 'like', "%{$search}%"));
        }

        if ($request->filled('id_cliente')) {
            $query->where('id_cliente', $request->id_cliente);
        }

        if ($request->filled('id_turno')) {
            $query->where('id_turno', $request->id_turno);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        // Usar where() en vez de whereDate() para que MySQL pueda usar el índice de fecha
        if ($request->filled('fecha_desde')) {
            $query->where('fecha', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->where('fecha', '<=', $request->fecha_hasta);
        }

        // orderBy('fecha') solo ordena por día — dos ventas del mismo día quedaban
        // en un orden indefinido entre sí (en la práctica, la más vieja primero,
        // por el orden físico de inserción). id desc desempata siempre a favor de
        // la más reciente, sin depender de la hora cargada por el cliente.
        $ventas = $query->orderBy('fecha', 'desc')->orderBy('id', 'desc')->paginate($request->per_page ?? 15);

        return response()->json(['success' => true, 'data' => $ventas]);
    }

    public function show($id): JsonResponse
    {
        $venta = Venta::with(['cliente', 'usuario', 'lineas.producto.categoria', 'pagos', 'devoluciones.lineas', 'factura'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $venta]);
    }

    public function store(CreateVentaRequest $request): JsonResponse
    {
        $turnoActivo = $this->turnoService->activo(auth()->user()->nro_usu, auth()->user()->id_sucursal);

        if (!$turnoActivo) {
            return response()->json([
                'success' => false,
                'message' => 'No podés registrar una venta sin abrir la caja primero',
            ], 422);
        }

        $ajuste = $request->ajuste;
        $esDescuento = !empty($ajuste) && ($ajuste['tipo'] ?? 'descuento') === 'descuento' && (float) ($ajuste['valor'] ?? 0) > 0;
        if ($esDescuento && !auth()->user()->chequearPermisos('aplicar-descuento-ventas')) {
            return response()->json([
                'success' => false,
                'message' => 'No tenés permiso para aplicar descuentos',
            ], 403);
        }

        // Una sola consulta de precios de lista para las dos validaciones de
        // abajo (antes cada una hacía la misma query por su cuenta, en CADA venta).
        $preciosCache = $this->preciosDeLineas($request->lineas, auth()->user()->empresa_id);

        if ($resp = $this->precioLineasSinPermiso($request->lineas, auth()->user()->empresa_id, $preciosCache)) {
            return $resp;
        }

        // Que un cajero PUEDA descontar no significa que no tenga que dejar
        // por qué — sin esto, el permiso por sí solo no deja ningún rastro de
        // a quién y por qué motivo se le vendió por debajo de lista.
        $hayRecargo = !empty($ajuste) && ($ajuste['tipo'] ?? '') === 'recargo' && (float) ($ajuste['valor'] ?? 0) > 0;
        $requiereMotivo = $esDescuento || $hayRecargo || $this->hayDescuentoEnLineas($request->lineas, auth()->user()->empresa_id, $preciosCache);
        if ($requiereMotivo && empty(trim((string) $request->motivo_descuento))) {
            return response()->json([
                'success' => false,
                'message' => 'Escribí el motivo del descuento o recargo antes de confirmar',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $venta = $this->ventaCreacionService->crear([
                'numero_ticket' => $request->numero_ticket,
                'id_cliente'    => $request->id_cliente,
                'id_usuario'    => auth()->user()->nro_usu,
                'estado'        => $request->estado ?? 'confirmada',
                'fecha'         => $request->fecha,
                'hora'          => $request->hora,
                'metodo_pago'   => $request->metodo_pago,
                'lineas'        => $request->lineas,
                'ajuste'        => $request->ajuste,
                'puntos_canjeados' => $request->puntos_canjeados,
                'motivo_descuento' => $request->motivo_descuento,
            ], auth()->user()->empresa_id, $turnoActivo->id);

            // Viene del botón "Convertir en venta" de Presupuestos.jsx, que manda
            // al POS con el carrito ya armado en vez de crear la venta directo por
            // API — recién acá, con la venta ya confirmada por el cajero, se marca
            // el presupuesto como convertido y se lo vincula. find() (no findOrFail)
            // a propósito: si el presupuesto ya no es válido (otro cajero lo
            // convirtió mientras tanto, o fue eliminado) la venta igual se registra,
            // simplemente sin el vínculo — no tiene sentido perder una venta real
            // por un dato que es solo de trazabilidad.
            if ($request->filled('id_presupuesto')) {
                $presupuesto = Presupuesto::find($request->id_presupuesto);
                if ($presupuesto && $presupuesto->estado === 'vigente') {
                    $presupuesto->update(['estado' => 'convertido', 'id_venta' => $venta->id]);
                }
            }

            DB::commit();
            DashboardController::invalidarCache(auth()->user()->empresa_id);

            $venta->load(['cliente', 'usuario', 'lineas.producto.categoria', 'pagos', 'devoluciones.lineas', 'factura']);

            return response()->json([
                'success' => true,
                'message' => 'Venta creada correctamente',
                'data'    => $venta,
            ], 201);

        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al crear la venta', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(UpdateVentaRequest $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $venta = Venta::findOrFail($id);

            if ($request->has('id_cliente')) {
                $cliente = Cliente::findOrFail($request->id_cliente);
                if (!$cliente->estado) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'El cliente no está activo'], 400);
                }
                $venta->id_cliente = $request->id_cliente;
                $venta->cuit       = $cliente->cuit;
            }

            // Sin edición de "lineas" a propósito: cambiar cantidades/productos de una
            // venta ya confirmada requeriría revertir y reaplicar stock y caja (como sí
            // hace ComprasController::update()), y esta rama nunca lo hizo — quedaba
            // desincronizando el stock en silencio. El front tampoco la usa. Para dar
            // de baja una venta existe /anular, que sí hace la reversión completa.

            if ($request->has('fecha'))  { $venta->fecha  = $request->fecha; }
            if ($request->has('estado')) { $venta->estado = $request->estado; }

            $venta->save();
            DB::commit();

            $venta->load(['cliente', 'usuario', 'lineas.producto.categoria', 'pagos', 'devoluciones.lineas', 'factura']);

            return response()->json(['success' => true, 'message' => 'Venta actualizada correctamente', 'data' => $venta]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al actualizar la venta', 'error' => $e->getMessage()], 500);
        }
    }

    public function changeStatus(Request $request, $id): JsonResponse
    {
        $request->validate(['estado' => 'required|in:pendiente,confirmada,cancelada']);

        if ($request->estado === 'cancelada') {
            return response()->json([
                'success' => false,
                'message' => 'Para anular una venta usá la acción "Anular", que también repone el stock y ajusta la caja',
            ], 400);
        }

        DB::beginTransaction();
        try {
            $venta = Venta::findOrFail($id);
            $venta->estado = $request->estado;
            $venta->save();
            DB::commit();

            $venta->load(['cliente', 'usuario', 'lineas.producto.categoria', 'pagos', 'devoluciones.lineas', 'factura']);

            return response()->json(['success' => true, 'message' => 'Estado actualizado', 'data' => $venta]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al cambiar el estado', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Anula una venta confirmada: repone el stock de cada línea, revierte el
     * efectivo que haya sumado a la caja (tanto el cobro original si fue en
     * efectivo, como cualquier pago parcial de una venta fiado) y borra los
     * pagos asociados. Si la caja donde se cobró ya está cerrada, el stock
     * igual se repone pero el ajuste de caja se omite (no se puede tocar el
     * saldo de un turno ya cerrado).
     */
    public function anular($id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $venta = Venta::with(['lineas', 'pagos'])->findOrFail($id);

            if ($venta->estado === 'cancelada') {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'La venta ya está anulada'], 400);
            }

            $cajaAjustada = true;

            // Repone stock en la misma sucursal donde se vendió. Una línea de combo
            // no tiene stock propio — hay que expandirla a sus componentes, igual
            // que VentaCreacionService al descontar (ver $demandaBase ahí).
            if ($venta->id_sucursal) {
                $idsLineas = $venta->lineas->pluck('id_producto')->filter()->unique();
                $productosLineas = Producto::whereIn('id', $idsLineas)->with('componentes')->get()->keyBy('id');

                // Una sola consulta agrupada para todas las líneas de la venta, en
                // vez de un sum() por línea dentro del loop de abajo.
                $devueltasPorLinea = DevolucionVentaLinea::whereIn('id_linea_venta', $venta->lineas->pluck('id_linea'))
                    ->selectRaw('id_linea_venta, SUM(cantidad) as total')
                    ->groupBy('id_linea_venta')
                    ->pluck('total', 'id_linea_venta');

                $demandaBase = [];
                foreach ($venta->lineas as $linea) {
                    if (!$linea->id_producto) continue;
                    $producto = $productosLineas[$linea->id_producto] ?? null;
                    if (!$producto) continue;

                    // Si esta línea ya tuvo una devolución parcial (ver
                    // DevolucionVentaService), esa parte del stock ya volvió —
                    // anular solo tiene que reponer lo que sigue afuera, si no
                    // duplicaría la reposición de lo ya devuelto.
                    $yaDevuelta = (float) ($devueltasPorLinea[$linea->id_linea] ?? 0);
                    $cantidadNeta = max(0, (float) $linea->cantidad - $yaDevuelta);
                    if ($cantidadNeta <= 0) continue;

                    if ($producto->es_combo) {
                        foreach ($producto->componentes as $comp) {
                            $demandaBase[$comp->id_producto] = ($demandaBase[$comp->id_producto] ?? 0) + $comp->cantidad * $cantidadNeta;
                        }
                    } else {
                        $demandaBase[$producto->id] = ($demandaBase[$producto->id] ?? 0) + $cantidadNeta;
                    }
                }

                foreach ($demandaBase as $idProducto => $cantidad) {
                    $this->stockService->ajustar($idProducto, $venta->id_sucursal, $cantidad, $venta->empresa_id);
                }
            }

            // Revertir el efectivo que sumó el cobro original (si no fue fiado)
            if ($venta->metodo_pago === 'efectivo' && (float) $venta->monto_cobrado > 0 && $venta->id_turno) {
                // lockForUpdate: mismo motivo que en VentaCreacionService/CajaController —
                // efectivo_actual se lee y se vuelve a escribir más abajo.
                $turnoOriginal = Turno::where('id', $venta->id_turno)->lockForUpdate()->first();
                if ($turnoOriginal && $turnoOriginal->estado === 'abierta') {
                    $turnoOriginal->efectivo_actual = max(0, $turnoOriginal->efectivo_actual - $venta->monto_cobrado);
                    $turnoOriginal->ventas_efectivo = max(0, $turnoOriginal->ventas_efectivo - $venta->monto_cobrado);
                    $turnoOriginal->save();
                } else {
                    $cajaAjustada = false;
                }
            }

            // Revertir cobros de una venta fiado (pagos_cliente en efectivo van a la caja abierta al momento de cobrarlos)
            if ($venta->pagos->isNotEmpty()) {
                $turnoActivo = null;
                foreach ($venta->pagos as $pago) {
                    if ($pago->metodo_pago === 'efectivo') {
                        $turnoActivo = $turnoActivo ?? $this->turnoService->activo(auth()->user()->nro_usu, auth()->user()->id_sucursal, lock: true);
                        if ($turnoActivo) {
                            $turnoActivo->efectivo_actual = max(0, $turnoActivo->efectivo_actual - $pago->monto);
                            $turnoActivo->ventas_efectivo = max(0, $turnoActivo->ventas_efectivo - $pago->monto);
                            $turnoActivo->save();
                        } else {
                            $cajaAjustada = false;
                        }
                    }
                    $pago->delete();
                }
            }

            $venta->estado        = 'cancelada';
            $venta->estado_pago    = 'pendiente';
            $venta->monto_cobrado  = 0;
            $venta->save();

            DB::commit();
            DashboardController::invalidarCache($venta->empresa_id);

            $venta->load(['cliente', 'usuario', 'lineas.producto.categoria', 'pagos', 'devoluciones.lineas', 'factura']);

            return response()->json([
                'success' => true,
                'message' => $cajaAjustada
                    ? 'Venta anulada: se repuso el stock y se ajustó la caja'
                    : 'Venta anulada y stock repuesto, pero no se pudo ajustar la caja (el turno correspondiente ya está cerrado)',
                'data' => $venta,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al anular la venta', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Devuelve parte (o el total) de una venta confirmada: una o varias
     * líneas, con su propia cantidad cada una — a diferencia de /anular, que
     * es todo o nada. Se puede llamar más de una vez sobre la misma venta.
     */
    public function devolucion(CreateDevolucionVentaRequest $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $devolucion = $this->devolucionVentaService->crear(
                (int) $id,
                $request->validated()['lineas'],
                $request->validated()['motivo'] ?? null,
            );

            DB::commit();
            DashboardController::invalidarCache(auth()->user()->empresa_id);

            return response()->json([
                'success' => true,
                'message' => $devolucion->caja_ajustada
                    ? 'Devolución registrada: se repuso el stock y se ajustó la caja'
                    : 'Devolución registrada y stock repuesto, pero no se pudo ajustar la caja (no hay un turno abierto)',
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
