<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreatePresupuestoRequest;
use App\Models\LineaPresupuesto;
use App\Models\Presupuesto;
use App\Models\Producto;
use App\Services\TurnoService;
use App\Services\VentaCreacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PresupuestosController extends Controller
{
    public function __construct(
        private VentaCreacionService $ventaCreacionService,
        private TurnoService $turnoService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Presupuesto::with(['cliente', 'usuario']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('cliente', fn($q) => $q->where('persona', 'like', "%{$search}%"));
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

        $presupuestos = $query->orderBy('fecha', 'desc')->orderBy('id', 'desc')->paginate($request->per_page ?? 15);

        return response()->json(['success' => true, 'data' => $presupuestos]);
    }

    public function show($id): JsonResponse
    {
        $presupuesto = Presupuesto::with(['cliente', 'usuario', 'lineas.producto', 'venta'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $presupuesto]);
    }

    public function store(CreatePresupuestoRequest $request): JsonResponse
    {
        $idSucursal = auth()->user()->id_sucursal;
        if (!$idSucursal) {
            return response()->json(['success' => false, 'message' => 'Tu usuario no tiene una sucursal asignada'], 422);
        }

        DB::beginTransaction();

        try {
            $ids = collect($request->lineas)->pluck('id_producto')->unique();
            $productosMap = Producto::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($request->lineas as $linea) {
                if (!$productosMap->has($linea['id_producto'])) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Producto no encontrado'], 404);
                }
            }

            $montoTotal = collect($request->lineas)->sum(fn($l) => $l['precio_venta'] * $l['cantidad']);

            $presupuesto = Presupuesto::create([
                'id_sucursal' => $idSucursal,
                'id_cliente'  => $request->id_cliente,
                'id_usuario'  => auth()->user()->nro_usu,
                'estado'      => 'vigente',
                'fecha'       => substr($request->fecha, 0, 10),
                'monto_total' => $montoTotal,
                'notas'       => $request->notas,
            ]);

            foreach ($request->lineas as $linea) {
                $producto = $productosMap[$linea['id_producto']];
                LineaPresupuesto::create([
                    'id_presupuesto' => $presupuesto->id,
                    'id_producto'    => $producto->id,
                    'nombre'         => $producto->producto,
                    'precio_venta'   => $linea['precio_venta'],
                    'cantidad'       => $linea['cantidad'],
                ]);
            }

            DB::commit();

            $presupuesto->load(['cliente', 'usuario', 'lineas.producto']);

            return response()->json(['success' => true, 'message' => 'Presupuesto guardado', 'data' => $presupuesto], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'No se pudo guardar el presupuesto'], 500);
        }
    }

    public function update($id, CreatePresupuestoRequest $request): JsonResponse
    {
        $presupuesto = Presupuesto::findOrFail($id);

        if ($presupuesto->estado !== 'vigente') {
            return response()->json(['success' => false, 'message' => 'Solo se puede editar un presupuesto vigente'], 422);
        }

        DB::beginTransaction();

        try {
            $ids = collect($request->lineas)->pluck('id_producto')->unique();
            $productosMap = Producto::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($request->lineas as $linea) {
                if (!$productosMap->has($linea['id_producto'])) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Producto no encontrado'], 404);
                }
            }

            $montoTotal = collect($request->lineas)->sum(fn($l) => $l['precio_venta'] * $l['cantidad']);

            $presupuesto->update([
                'id_cliente'  => $request->id_cliente,
                'fecha'       => substr($request->fecha, 0, 10),
                'monto_total' => $montoTotal,
                'notas'       => $request->notas,
            ]);

            $presupuesto->lineas()->delete();
            foreach ($request->lineas as $linea) {
                $producto = $productosMap[$linea['id_producto']];
                LineaPresupuesto::create([
                    'id_presupuesto' => $presupuesto->id,
                    'id_producto'    => $producto->id,
                    'nombre'         => $producto->producto,
                    'precio_venta'   => $linea['precio_venta'],
                    'cantidad'       => $linea['cantidad'],
                ]);
            }

            DB::commit();

            $presupuesto->load(['cliente', 'usuario', 'lineas.producto']);

            return response()->json(['success' => true, 'message' => 'Presupuesto actualizado', 'data' => $presupuesto]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'No se pudo actualizar el presupuesto'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $presupuesto = Presupuesto::findOrFail($id);

        if ($presupuesto->estado === 'convertido') {
            return response()->json(['success' => false, 'message' => 'No se puede eliminar un presupuesto ya convertido en venta'], 422);
        }

        $presupuesto->delete();

        return response()->json(['success' => true, 'message' => 'Presupuesto eliminado']);
    }

    /**
     * Convierte un presupuesto vigente en una venta real — recién acá se
     * descuenta stock y se toca la caja (nunca al guardar el presupuesto).
     * Reusa VentaCreacionService::crear() en vez de duplicar esa lógica, así
     * que hereda las mismas validaciones/efectos que una venta manual del POS.
     */
    public function convertir($id): JsonResponse
    {
        $presupuesto = Presupuesto::with('lineas')->findOrFail($id);

        if ($presupuesto->estado !== 'vigente') {
            return response()->json(['success' => false, 'message' => 'Este presupuesto ya no está vigente'], 422);
        }

        $turnoActivo = $this->turnoService->activo(auth()->user()->nro_usu, auth()->user()->id_sucursal);
        if (!$turnoActivo) {
            return response()->json(['success' => false, 'message' => 'No podés convertir un presupuesto en venta sin abrir la caja primero'], 422);
        }

        DB::beginTransaction();

        try {
            $venta = $this->ventaCreacionService->crear([
                'id_cliente'  => $presupuesto->id_cliente,
                'id_usuario'  => auth()->user()->nro_usu,
                'estado'      => 'confirmada',
                'fecha'       => now()->format('Y-m-d'),
                'hora'        => now()->format('H:i'),
                'metodo_pago' => request('metodo_pago', 'efectivo'),
                'lineas'      => $presupuesto->lineas->map(fn($l) => [
                    'id_producto'  => $l->id_producto,
                    'nombre'       => $l->nombre,
                    'precio_venta' => (float) $l->precio_venta,
                    'cantidad'     => (float) $l->cantidad,
                ])->all(),
            ], auth()->user()->empresa_id, $turnoActivo->id);

            $presupuesto->update(['estado' => 'convertido', 'id_venta' => $venta->id]);

            DB::commit();
            DashboardController::invalidarCache(auth()->user()->empresa_id);

            $venta->load(['cliente', 'usuario', 'lineas.producto.categoria']);

            return response()->json(['success' => true, 'message' => 'Presupuesto convertido en venta', 'data' => $venta]);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'No se pudo convertir el presupuesto'], 500);
        }
    }
}
