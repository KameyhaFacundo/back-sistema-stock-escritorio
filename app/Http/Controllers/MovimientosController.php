<?php

namespace App\Http\Controllers;

use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Rules\CantidadValidaParaProducto;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MovimientosController extends Controller
{
    public function __construct(private StockService $stockService) {}

    public function index(Request $request)
    {
        $query = MovimientoStock::with(['usuario:nro_usu,des_usu', 'sucursal:id,nombre'])->latest('fecha')->latest('id');

        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('producto', 'like', "%{$search}%")
                  ->orWhere('codigo',   'like', "%{$search}%");
            });
        }

        if ($fecha = $request->fecha) {
            $query->whereDate('fecha', $fecha);
        }

        if ($tipo = $request->tipo) {
            $query->where('tipo', $tipo);
        }

        if ($idSucursal = $request->id_sucursal) {
            $query->where('id_sucursal', $idSucursal);
        }

        $perPage = min((int) ($request->per_page ?? 100), 500);
        $data    = $query->paginate($perPage);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_producto' => 'nullable|exists:productos,id',
            'producto'    => 'required|string|max:255',
            'codigo'      => 'nullable|string|max:100',
            'tipo'        => 'required|in:venta,compra,ajuste',
            'sub_tipo'    => 'nullable|string|max:255',
            'cantidad'    => ['required', 'numeric', new CantidadValidaParaProducto()],
            // Obligatoria solo para 'ajuste' (ver abajo, con mensaje propio) — un
            // ajuste manual sin motivo es la tapadera perfecta para un faltante:
            // "cantidad" ya justifica el número, pero nada explica el POR QUÉ.
            // Este mismo endpoint también sirve para registrar movimientos de
            // venta/compra ya justificados por su propio origen, ahí sigue opcional.
            'nota'        => 'nullable|string|max:500',
            'fecha'       => 'required|date',
            'hora'        => 'nullable|string|max:10',
        ]);

        // Solo para bajas: un ajuste que RESTA stock sin motivo es la tapadera
        // perfecta para un faltante. Una suba no oculta nada — el front ya la
        // deja libre y opcional (ver MOTIVOS_BAJA en Movimientos.jsx), así que
        // exigirlo ahí también sería fricción sin ningún beneficio real.
        if ($validated['tipo'] === 'ajuste' && (float) $validated['cantidad'] < 0 && empty(trim((string) ($validated['nota'] ?? '')))) {
            return response()->json([
                'success' => false,
                'message' => 'Escribí el motivo del ajuste antes de guardarlo',
            ], 422);
        }

        $idSucursal = auth()->user()?->id_sucursal;
        if (!$idSucursal) {
            return response()->json(['success' => false, 'message' => 'Tu usuario no tiene una sucursal asignada'], 422);
        }

        $validated['fecha']       = date('Y-m-d', strtotime($validated['fecha']));
        $validated['id_usuario']  = auth()->user()?->nro_usu;
        $validated['id_sucursal'] = $idSucursal;

        DB::beginTransaction();
        try {
            // Los ajustes manuales actualizan el stock aquí de forma atómica
            if ($validated['tipo'] === 'ajuste' && !empty($validated['id_producto'])) {
                $producto = Producto::findOrFail($validated['id_producto']);
                // Un producto madre con variantes no tiene stock propio (vive en
                // cada variante) — se ajusta el talle puntual.
                if ($producto->tiene_variantes) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => "'{$producto->producto}' tiene variantes — ajustá el talle puntual, no el producto general"], 422);
                }
                $this->stockService->ajustar($producto->id, $idSucursal, $validated['cantidad'], $producto->empresa_id);
            }

            $mov = MovimientoStock::create($validated);
            $mov->load('usuario:nro_usu,des_usu');
            DB::commit();
        } catch (\RuntimeException $e) {
            // Stock insuficiente para una baja — es un error de validación, no una
            // falla del servidor. Antes caía en el catch genérico de abajo y el
            // usuario veía un 500 sin el motivo real (parecía un error de conexión).
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al registrar el movimiento', 'error' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'data' => $mov], 201);
    }

    // POST /movimientos/transferencia — mueve stock de una sucursal a otra y deja
    // el par de movimientos (salida/entrada) que ya arma StockService::transferir().
    public function transferencia(Request $request)
    {
        $validated = $request->validate([
            'id_producto'         => 'required|exists:productos,id',
            'cantidad'            => ['required', 'numeric', 'min:0.01', new CantidadValidaParaProducto()],
            'id_sucursal_origen'  => 'required|exists:sucursales,id|different:id_sucursal_destino',
            'id_sucursal_destino' => 'required|exists:sucursales,id',
        ]);

        DB::beginTransaction();
        try {
            $producto  = Producto::findOrFail($validated['id_producto']);
            // Un producto madre con variantes no tiene stock propio (vive en cada
            // variante) — se transfiere el talle puntual, no el producto general.
            if ($producto->tiene_variantes) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => "'{$producto->producto}' tiene variantes — transferí el talle puntual, no el producto general"], 422);
            }
            $resultado = $this->stockService->transferir(
                $producto,
                $validated['id_sucursal_origen'],
                $validated['id_sucursal_destino'],
                $validated['cantidad'],
                auth()->user()->nro_usu,
            );
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transferencia registrada',
                'data'    => $resultado,
            ], 201);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al registrar la transferencia', 'error' => $e->getMessage()], 500);
        }
    }

}
