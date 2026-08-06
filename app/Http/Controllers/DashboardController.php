<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use App\Models\Producto;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * El cache driver configurado ('file') no soporta tags, así que no se puede
     * invalidar por patrón. En su lugar, cada empresa tiene un número de versión:
     * al mutar una venta (crear/anular) se incrementa, lo que automáticamente
     * "vacía" todos los rangos de fecha cacheados previamente para esa empresa
     * (quedan huérfanos con la versión vieja y nunca más se leen).
     */
    public static function invalidarCache(int $empresaId): void
    {
        $key = "dashboard:stats:version:{$empresaId}";
        Cache::put($key, (int) Cache::get($key, 1) + 1, now()->addDays(7));
    }

    public function stats(Request $request)
    {
        $user = auth()->user();

        if (!$user->empresa_id) {
            return response()->json(['success' => true, 'data' => $this->emptyStats()]);
        }

        $empresaId  = $user->empresa_id;
        $idSucursal = $user->id_sucursal;
        $desde = $request->get('desde', Carbon::today()->toDateString());
        $hasta = $request->get('hasta', Carbon::today()->toDateString());
        $version = (int) Cache::get("dashboard:stats:version:{$empresaId}", 1);

        $cacheKey = "dashboard:stats:{$empresaId}:{$desde}:{$hasta}:{$idSucursal}:v{$version}";

        return Cache::remember($cacheKey, 30, function () use ($empresaId, $idSucursal, $desde, $hasta) {

            // DB::table (no el modelo Venta) para estos agregados — Venta define
            // getSaldoPendienteAttribute() y un selectRaw+Eloquent que algún día
            // agregue un alias que choque con un accessor volvería a fallar en
            // silencio, como pasó en DeudasController@resumen.
            $ventasQuery = DB::table('ventas')
                ->where('empresa_id', $empresaId)
                ->where('estado', '!=', 'cancelada')
                ->whereNull('deleted_at')
                ->whereBetween('fecha', [$desde, $hasta]);

            $agg = (clone $ventasQuery)
                ->selectRaw('COUNT(*) as total, COALESCE(SUM(monto_total), 0) as ingresos')
                ->first();
            $totalVentas    = (int) $agg->total;
            $totalIngresos  = (float) $agg->ingresos;
            $ticketPromedio = $totalVentas > 0 ? round($totalIngresos / $totalVentas, 2) : 0;

            $hoy = Carbon::today()->toDateString();
            $aggHoy = DB::table('ventas')
                ->where('empresa_id', $empresaId)
                ->where('estado', '!=', 'cancelada')
                ->whereNull('deleted_at')
                ->where('fecha', $hoy)
                ->selectRaw('COUNT(*) as total, COALESCE(SUM(monto_total), 0) as ingresos')
                ->first();
            $ventasHoy   = (int) $aggHoy->total;
            $ingresosHoy = (float) $aggHoy->ingresos;

            // COALESCE(id_producto_padre, id): una remera vendida en 6 talles es
            // 6 productos distintos en lineas_ventas.id_producto — sin esto,
            // "top productos" la mostraría como 6 filas separadas en vez de una
            // sola agregada. Left join porque una línea de "monto libre" no
            // tiene id_producto (queda agrupada aparte, igual que antes).
            $topIds = DB::table('lineas_ventas')
                ->join('ventas', 'lineas_ventas.id_venta', '=', 'ventas.id')
                ->leftJoin('productos', 'productos.id', '=', 'lineas_ventas.id_producto')
                ->where('ventas.empresa_id', $empresaId)
                ->where('ventas.estado', '!=', 'cancelada')
                ->whereBetween('ventas.fecha', [$desde, $hasta])
                ->select(
                    DB::raw('COALESCE(productos.id_producto_padre, productos.id, lineas_ventas.id_producto) as id_producto'),
                    DB::raw('SUM(lineas_ventas.cantidad) as total_unidades'),
                    DB::raw('SUM(lineas_ventas.precio_venta * lineas_ventas.cantidad) as total_ingresos')
                )
                ->groupBy(DB::raw('COALESCE(productos.id_producto_padre, productos.id, lineas_ventas.id_producto)'))
                ->orderByDesc('total_unidades')
                ->limit(10)
                ->get();

            $productosMap = Producto::whereIn('id', $topIds->pluck('id_producto'))
                ->get()
                ->keyBy('id');

            $topProductos = $topIds->map(function ($row) use ($productosMap) {
                $producto = $productosMap->get($row->id_producto);
                return [
                    'nombre'    => $producto?->producto ?? 'Producto #' . $row->id_producto,
                    'unidades'  => (float) $row->total_unidades,
                    'ingresos'  => (float) $row->total_ingresos,
                ];
            });

            // El stock vive en producto_stock (por sucursal) — ver Producto::stockEnSucursal.
            // Acá SÍ queda una fila por variante (no se agrupa por padre, a
            // diferencia de "top productos") — el stock bajo es real y puntual
            // de cada talle, agruparlo escondería justamente cuál talla falta.
            // El join a talles solo es para poder mostrar cuál talla es cada fila.
            $stockBajoQuery = DB::table('producto_stock')
                ->join('productos', 'productos.id', '=', 'producto_stock.id_producto')
                ->leftJoin('talles', 'talles.id', '=', 'productos.id_talle')
                ->where('productos.empresa_id', $empresaId)
                ->where('productos.estado', 1)
                ->whereColumn('producto_stock.stock', '<=', 'producto_stock.stock_minimo');

            if ($idSucursal) {
                $stockBajoQuery->where('producto_stock.id_sucursal', $idSucursal);
            }

            $stockBajo = $stockBajoQuery
                ->orderBy('producto_stock.stock')
                ->limit(20)
                ->get([
                    'productos.id',
                    // CONCAT() es de MySQL — esta app solo corre sobre SQLite (ver
                    // CLAUDE.md), que concatena con ||. Con CONCAT esto tira
                    // "no such function" apenas hay una fila real de stock bajo
                    // con talle asociado (no lo agarraba ningún test hasta ahora).
                    DB::raw("CASE WHEN talles.valor IS NOT NULL THEN productos.producto || ' (' || talles.valor || ')' ELSE productos.producto END as nombre"),
                    'productos.codigo', 'producto_stock.stock', 'producto_stock.stock_minimo as alerta',
                ]);

            // Valor de inventario (stock × costo / stock × precio) sumado en la
            // base, no en el cliente — antes Dashboard.jsx lo calculaba sumando
            // el array de productos cargado en memoria (limitado a los primeros
            // 500 del catálogo, ver ProductosContext), así que con un catálogo
            // más grande el número quedaba silenciosamente subestimado. Mismo
            // criterio de joins/filtros que $stockBajoQuery de arriba.
            $valorInventarioQuery = DB::table('producto_stock')
                ->join('productos', 'productos.id', '=', 'producto_stock.id_producto')
                ->where('productos.empresa_id', $empresaId)
                ->where('productos.estado', 1);

            if ($idSucursal) {
                $valorInventarioQuery->where('producto_stock.id_sucursal', $idSucursal);
            }

            $valorInventarioRow = $valorInventarioQuery
                ->selectRaw('COALESCE(SUM(producto_stock.stock * productos.costo), 0) as costo, COALESCE(SUM(producto_stock.stock * productos.precio), 0) as venta')
                ->first();

            $valorInventario = [
                'costo' => (float) ($valorInventarioRow->costo ?? 0),
                'venta' => (float) ($valorInventarioRow->venta ?? 0),
            ];

            // Filtrar por sucursal igual que CajaController::turnoActivo — sin esto,
            // en una empresa con varias sucursales abiertas a la vez (normal en
            // Pro/IA), este widget podía mostrar la caja de OTRA sucursal en vez
            // de "no hay caja abierta acá" (->latest() elegía cualquiera de las
            // que estuvieran abiertas en toda la empresa).
            $cajaQuery = \App\Models\Turno::with(['movimientos' => fn($q) => $q->orderBy('hora')])
                ->where('empresa_id', $empresaId)
                ->where('estado', 'abierta');

            if ($idSucursal) {
                $cajaQuery->where('id_sucursal', $idSucursal);
            }

            $caja = $cajaQuery->latest()->first();

            $cajaData = null;
            if ($caja) {
                $cajaData = [
                    'abierta'          => true,
                    'horaApertura'     => $caja->hora_apertura,
                    'montoInicial'     => (float) $caja->monto_inicial,
                    'ventasEfectivo'   => (float) $caja->ventas_efectivo,
                    'efectivoActual'   => (float) $caja->efectivo_actual,
                    'movimientosDetalle' => $caja->movimientos->map(fn($m) => [
                        'id'     => $m->id,
                        'tipo'   => $m->tipo,
                        'monto'  => (float) $m->monto,
                        'motivo' => $m->motivo,
                        'hora'   => $m->hora,
                    ])->toArray(),
                ];
            }

            $deudasProveedores = DB::table('compras')
                ->join('proveedores', 'compras.id_proveedor', '=', 'proveedores.id')
                ->where('compras.empresa_id', $empresaId)
                ->whereIn('compras.estado_deuda', ['pendiente', 'parcial'])
                ->select('proveedores.persona as proveedor', DB::raw('SUM(compras.monto_total - compras.monto_pagado) as saldo'))
                ->groupBy('compras.id_proveedor', 'proveedores.persona')
                ->having('saldo', '>', 0)
                ->get();

            $fiadosClientes = DB::table('ventas')
                ->join('clientes', 'ventas.id_cliente', '=', 'clientes.id')
                ->where('ventas.empresa_id', $empresaId)
                ->where('ventas.estado', '!=', 'cancelada')
                ->whereIn('ventas.estado_pago', ['pendiente', 'parcial'])
                ->select('clientes.persona as cliente', DB::raw('SUM(ventas.monto_total - ventas.monto_cobrado) as saldo'))
                ->groupBy('ventas.id_cliente', 'clientes.persona')
                ->having('saldo', '>', 0)
                ->get();

            return response()->json([
                'success' => true,
                'data'    => [
                    'totalVentas'    => $totalVentas,
                    'totalIngresos'  => (float) $totalIngresos,
                    'ticketPromedio' => (float) $ticketPromedio,
                    'ventasHoy'      => $ventasHoy,
                    'ingresosHoy'    => (float) $ingresosHoy,
                    'topProductos'   => $topProductos,
                    'stockBajo'      => $stockBajo,
                    'valorInventario' => $valorInventario,
                    'caja'           => $cajaData ?? ['abierta' => false],
                    'deudas'         => [
                        'proveedores' => $deudasProveedores,
                        'clientes'    => $fiadosClientes,
                    ],
                ],
            ]);
        });
    }

    /**
     * Más vendidos (mismo patrón de agregación que topProductos en stats(),
     * pero con límite configurable) + productos sin ventas en los últimos
     * $dias días — para detectar capital parado en mercadería que no rota.
     * Endpoint aparte de stats() (no comparte su caché de 30s) porque acá
     * el rango de "sin movimiento" es independiente del desde/hasta que
     * usa el resto del dashboard.
     */
    public function rankingProductos(Request $request)
    {
        $user = auth()->user();

        if (!$user->empresa_id) {
            return response()->json(['success' => true, 'data' => ['masVendidos' => [], 'sinMovimiento' => []]]);
        }

        $empresaId  = $user->empresa_id;
        $idSucursal = $user->id_sucursal;
        $desde = $request->get('desde', Carbon::today()->toDateString());
        $hasta = $request->get('hasta', Carbon::today()->toDateString());
        $dias  = (int) $request->get('dias', 30);
        $limit = min((int) $request->get('limit', 20), 100);
        $version = (int) Cache::get("dashboard:stats:version:{$empresaId}", 1);

        $cacheKey = "dashboard:ranking:{$empresaId}:{$desde}:{$hasta}:{$dias}:{$limit}:{$idSucursal}:v{$version}";

        return Cache::remember($cacheKey, 30, function () use ($empresaId, $idSucursal, $desde, $hasta, $dias, $limit) {
            // Mismo COALESCE(id_producto_padre, id) que topProductos en stats() —
            // ver ese comentario para el porqué (variantes de talle no se duplican).
            $topIds = DB::table('lineas_ventas')
                ->join('ventas', 'lineas_ventas.id_venta', '=', 'ventas.id')
                ->leftJoin('productos', 'productos.id', '=', 'lineas_ventas.id_producto')
                ->where('ventas.empresa_id', $empresaId)
                ->where('ventas.estado', '!=', 'cancelada')
                ->whereBetween('ventas.fecha', [$desde, $hasta])
                ->select(
                    DB::raw('COALESCE(productos.id_producto_padre, productos.id, lineas_ventas.id_producto) as id_producto'),
                    DB::raw('SUM(lineas_ventas.cantidad) as total_unidades'),
                    DB::raw('SUM(lineas_ventas.precio_venta * lineas_ventas.cantidad) as total_ingresos')
                )
                ->groupBy(DB::raw('COALESCE(productos.id_producto_padre, productos.id, lineas_ventas.id_producto)'))
                ->orderByDesc('total_unidades')
                ->limit($limit)
                ->get();

            $productosMap = Producto::whereIn('id', $topIds->pluck('id_producto'))->get()->keyBy('id');

            $masVendidos = $topIds->map(function ($row) use ($productosMap) {
                $producto = $productosMap->get($row->id_producto);
                return [
                    'id'        => $row->id_producto,
                    'nombre'    => $producto?->producto ?? 'Producto #' . $row->id_producto,
                    'codigo'    => $producto?->codigo,
                    'unidades'  => (float) $row->total_unidades,
                    'ingresos'  => (float) $row->total_ingresos,
                ];
            })->values();

            // Productos activos, sin variantes-sombrilla (esas no tienen stock
            // propio — ver el mismo criterio en el join de stockBajo más arriba),
            // dados de alta antes del inicio de la ventana (para no marcar como
            // "sin movimiento" algo que se cargó ayer y todavía no tuvo chance de
            // venderse), con stock real, y sin ninguna línea de venta confirmada
            // en los últimos $dias días.
            $desdeSinMovimiento = Carbon::today()->subDays($dias)->toDateString();

            $stockQuery = DB::table('producto_stock')->select('id_producto', DB::raw('SUM(stock) as stock'));
            if ($idSucursal) $stockQuery->where('id_sucursal', $idSucursal);
            $stockQuery->groupBy('id_producto');

            $sinMovimiento = DB::table('productos')
                ->leftJoinSub($stockQuery, 'ps', 'ps.id_producto', '=', 'productos.id')
                ->where('productos.empresa_id', $empresaId)
                ->where('productos.estado', 1)
                ->where('productos.tiene_variantes', false)
                ->where('productos.created_at', '<=', $desdeSinMovimiento)
                ->whereRaw('COALESCE(ps.stock, 0) > 0')
                ->whereNotIn('productos.id', function ($q) use ($empresaId, $desdeSinMovimiento) {
                    $q->select('lineas_ventas.id_producto')
                        ->from('lineas_ventas')
                        ->join('ventas', 'lineas_ventas.id_venta', '=', 'ventas.id')
                        ->where('ventas.empresa_id', $empresaId)
                        ->where('ventas.estado', '!=', 'cancelada')
                        ->where('ventas.fecha', '>=', $desdeSinMovimiento)
                        ->whereNotNull('lineas_ventas.id_producto');
                })
                ->select(
                    'productos.id', 'productos.producto as nombre', 'productos.codigo',
                    DB::raw('COALESCE(ps.stock, 0) as stock'),
                    DB::raw('COALESCE(ps.stock, 0) * productos.costo as valorCapital')
                )
                ->orderByDesc('valorCapital')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data'    => [
                    'masVendidos'   => $masVendidos,
                    'sinMovimiento' => $sinMovimiento,
                    'diasSinMovimiento' => $dias,
                ],
            ]);
        });
    }

    private function emptyStats(): array
    {
        return [
            'totalVentas'    => 0,
            'totalIngresos'  => 0,
            'ticketPromedio' => 0,
            'ventasHoy'      => 0,
            'ingresosHoy'    => 0,
            'topProductos'   => [],
            'stockBajo'      => [],
            'valorInventario' => ['costo' => 0, 'venta' => 0],
            'caja'           => ['abierta' => false],
            'deudas'         => ['proveedores' => [], 'clientes' => []],
        ];
    }
}
