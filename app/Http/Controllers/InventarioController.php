<?php

namespace App\Http\Controllers;

use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventarioController extends Controller
{
    public function __construct(private StockService $stockService) {}

    // POST /inventario/guardar — recibe una lista de {id_producto, stock_fisico}
    // y crea un movimiento de tipo "ajuste" por cada producto cuyo stock real
    // difiera del stock físico contado. Todo dentro de una transacción.
    public function guardar(Request $request)
    {
        $validated = $request->validate([
            'productos'             => 'required|array|min:1',
            'productos.*.id_producto' => 'required|exists:productos,id',
            'productos.*.stock_fisico' => 'required|numeric|min:0',
            'productos.*.producto'   => 'required|string|max:255',
            'productos.*.codigo'     => 'nullable|string|max:100',
        ]);

        $user       = auth()->user();
        $idSucursal = $user?->id_sucursal;
        $idUsuario  = $user?->nro_usu;
        $empresaId  = $user?->empresa_id;

        if (!$idSucursal) {
            return response()->json([
                'success' => false,
                'message' => 'Tu usuario no tiene una sucursal asignada',
            ], 422);
        }

        $fecha   = now()->format('Y-m-d');
        $hora    = now()->format('H:i');
        $creados = [];
        $salteadosVariantes = 0;
        $errores = [];

        DB::beginTransaction();
        try {
            foreach ($validated['productos'] as $item) {
                $producto = Producto::with(['variantes.talles.stocks'])->find($item['id_producto']);
                if (!$producto) continue;

                // Un producto madre con variantes no tiene stock propio — no se
                // puede ajustar al nivel del producto general. Se saltea y se
                // informa en la respuesta para que el front lo muestre.
                if ($producto->tiene_variantes) {
                    $salteadosVariantes++;
                    $errores[] = "{$producto->producto} tiene variantes — ajustá cada talle por separado";
                    continue;
                }

                $stockActual = $this->stockService->disponible($producto->id, $idSucursal);
                $stockFisico = (float) $item['stock_fisico'];
                $delta       = $stockFisico - $stockActual;

                if (abs($delta) < 0.001) continue; // sin diferencia, nada que ajustar

                $this->stockService->ajustar($producto->id, $idSucursal, $delta, $empresaId);

                $mov = MovimientoStock::create([
                    'empresa_id'  => $empresaId,
                    'id_sucursal' => $idSucursal,
                    'id_producto' => $producto->id,
                    'id_usuario'  => $idUsuario,
                    'producto'    => $item['producto'],
                    'codigo'      => $item['codigo'] ?? $producto->codigo,
                    'tipo'        => 'ajuste',
                    'sub_tipo'    => 'Toma de inventario',
                    'cantidad'    => $delta,
                    'nota'        => "Stock sistema: {$stockActual} → Stock físico: {$stockFisico}",
                    'fecha'       => $fecha,
                    'hora'        => $hora,
                ]);

                $creados[] = [
                    'id'           => $mov->id,
                    'producto'     => $item['producto'],
                    'stock_antes'  => $stockActual,
                    'stock_fisico'  => $stockFisico,
                    'delta'        => round($delta, 2),
                ];
            }

            DB::commit();
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar el inventario',
                'error'   => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success'              => true,
            'message'              => 'Inventario guardado correctamente',
            'ajustes'              => count($creados),
            'sin_cambios'          => count($validated['productos']) - count($creados) - $salteadosVariantes,
            'salteados_variantes' => $salteadosVariantes,
            'errores'              => $errores,
            'data'                 => $creados,
        ]);
    }
}
