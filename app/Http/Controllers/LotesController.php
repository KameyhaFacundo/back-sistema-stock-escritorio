<?php

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LotesController extends Controller
{
    public function __construct(private StockService $stockService) {}

    public function index(Request $request): JsonResponse
    {
        $query = Lote::with(['producto:id,producto,codigo', 'sucursal:id,nombre'])
            ->where('empresa_id', auth()->user()->empresa_id);

        if ($request->filled('id_sucursal')) {
            $query->where('id_sucursal', $request->id_sucursal);
        }
        if ($request->filled('id_producto')) {
            $query->where('id_producto', $request->id_producto);
        }
        if ($request->filled('solo_activos')) {
            $query->where('cantidad', '>', 0);
        }

        $perPage = min((int) $request->input('per_page', 20), 500);
        $data = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json(['data' => $data]);
    }

    public function porProducto(int $idProducto): JsonResponse
    {
        $lotes = Lote::with('sucursal:id,nombre')
            ->where('empresa_id', auth()->user()->empresa_id)
            ->where('id_producto', $idProducto)
            ->where('cantidad', '>', 0)
            ->orderByRaw('COALESCE(fecha_vencimiento, \'9999-12-31\') ASC')
            ->get();

        return response()->json(['data' => $lotes]);
    }

    public function proximosAVencer(Request $request): JsonResponse
    {
        $dias = (int) $request->input('dias', 7);
        $lotes = Lote::with('producto:id,producto,codigo')
            ->where('empresa_id', auth()->user()->empresa_id)
            ->proximosAVencer($dias)
            ->get();

        return response()->json(['data' => $lotes]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_producto'        => 'required|integer|exists:productos,id',
            'id_sucursal'        => 'nullable|integer|exists:sucursales,id',
            'cantidad'           => 'required|numeric|min:0.01',
            'fecha_vencimiento'  => 'nullable|date',
            'id_compra'          => 'nullable|integer|exists:compras,id',
        ]);

        $idSucursal = $data['id_sucursal'] ?? auth()->user()->id_sucursal;
        if (!$idSucursal) {
            return response()->json(['success' => false, 'message' => 'Tu usuario no tiene una sucursal asignada'], 422);
        }

        $producto = Producto::findOrFail($data['id_producto']);

        // Pasa por StockService::agregar() (no Lote::create directo) para que
        // producto_stock.stock — lo que lee el listado de Productos — suba junto
        // con el lote nuevo. Creando el lote a mano quedaba desincronizado con
        // ese total, la misma clase de bug que VentaCreacionService ya evita del
        // otro lado (ver sus comentarios sobre restar()).
        $this->stockService->agregar(
            $producto->id,
            $idSucursal,
            (float) $data['cantidad'],
            $producto->empresa_id,
            $data['fecha_vencimiento'] ?? null,
            $data['id_compra'] ?? null,
            auth()->user()?->nro_usu,
        );

        $lote = Lote::where('id_producto', $producto->id)
            ->where('id_sucursal', $idSucursal)
            ->latest('id')
            ->first();

        return response()->json(['data' => $lote->load('producto')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'cantidad'           => 'sometimes|numeric|min:0',
            'fecha_vencimiento'  => 'nullable|date',
        ]);

        return DB::transaction(function () use ($data, $id) {
            $lote = Lote::where('empresa_id', auth()->user()->empresa_id)->lockForUpdate()->findOrFail($id);

            // Editar la cantidad de un lote existente cambia cuánto aporta al stock
            // total — sin mover ese mismo delta en producto_stock.stock, el listado
            // de Productos y StockService::disponible() (suma de lotes) quedan
            // desincronizados entre sí.
            if (array_key_exists('cantidad', $data) && (float) $data['cantidad'] !== (float) $lote->cantidad) {
                $delta = (float) $data['cantidad'] - (float) $lote->cantidad;
                $fila = ProductoStock::where('id_producto', $lote->id_producto)
                    ->where('id_sucursal', $lote->id_sucursal)
                    ->lockForUpdate()
                    ->first();
                if ($fila) {
                    $fila->stock = max(0, $fila->stock + $delta);
                    $fila->save();
                }
                $this->stockService->invalidarCacheProductos($lote->empresa_id);
            }

            $lote->update($data);

            return response()->json(['data' => $lote->fresh('producto')]);
        });
    }

    public function destroy(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $lote = Lote::where('empresa_id', auth()->user()->empresa_id)->lockForUpdate()->findOrFail($id);

            // Mismo motivo que en update(): borrar el lote saca su cantidad del
            // total disponible, hay que descontarla también de producto_stock.stock.
            $fila = ProductoStock::where('id_producto', $lote->id_producto)
                ->where('id_sucursal', $lote->id_sucursal)
                ->lockForUpdate()
                ->first();
            if ($fila) {
                $fila->stock = max(0, $fila->stock - $lote->cantidad);
                $fila->save();
            }

            $empresaId = $lote->empresa_id;
            $lote->delete();
            $this->stockService->invalidarCacheProductos($empresaId);

            return response()->json(null, 204);
        });
    }
}
