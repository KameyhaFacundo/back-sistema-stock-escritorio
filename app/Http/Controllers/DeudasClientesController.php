<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\PagoCliente;
use App\Services\TurnoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeudasClientesController extends Controller
{
    public function __construct(private TurnoService $turnoService) {}

    // GET /deudas-clientes
    // Por defecto solo trae ventas con saldo pendiente (pendiente/parcial) —
    // las ya saldadas dejan de aparecer acá y, con ellas, se pierde de vista
    // qué se le compró y cobró a ese cliente en el pasado. `incluir_pagadas`
    // las suma sin tocar el comportamiento default (usado por la lista
    // general de Deudas, que no debe llenarse de ventas ya cobradas).
    public function index(Request $request): JsonResponse
    {
        $estados = ['pendiente', 'parcial'];
        if ($request->boolean('incluir_pagadas')) {
            $estados[] = 'pagado';
        }

        $query = Venta::with(['cliente', 'pagos', 'lineas.producto', 'usuario'])
            ->whereIn('estado_pago', $estados)
            ->where('estado', '!=', 'cancelada')
            ->orderBy('fecha', 'desc');

        if ($request->id_cliente) {
            $query->where('id_cliente', $request->id_cliente);
        }

        $deudas = $query->paginate($request->per_page ?? 50);

        // DB::table (no el modelo Venta) para no depender de que ningún alias
        // futuro choque con un accessor de Venta (ver resumen()). where() (no
        // when()) a propósito: un $empresaIdTotal null tiene que dar 0, no
        // saltarse el filtro y sumar la deuda de TODAS las empresas.
        $empresaIdTotal = auth('api')->user()?->empresa_id;
        $totalDeuda = DB::table('ventas')
            ->where('empresa_id', $empresaIdTotal)
            ->whereIn('estado_pago', ['pendiente', 'parcial'])
            ->where('estado', '!=', 'cancelada')
            ->whereNull('deleted_at')
            ->selectRaw('SUM(monto_total - monto_cobrado) as total')
            ->value('total') ?? 0;

        return response()->json([
            'success'     => true,
            'data'        => $deudas,
            'total_deuda' => (float) $totalDeuda,
        ]);
    }

    // GET /deudas-clientes/resumen
    public function resumen(): JsonResponse
    {
        // where() (no when()): un $empresaId null tiene que dar 0 filas, no
        // saltarse el filtro y agregar la deuda de TODAS las empresas.
        $empresaId = auth('api')->user()?->empresa_id;

        $resumen = DB::table('ventas')
            ->join('clientes', 'ventas.id_cliente', '=', 'clientes.id')
            ->where('ventas.empresa_id', $empresaId)
            ->whereIn('ventas.estado_pago', ['pendiente', 'parcial'])
            ->where('ventas.estado', '!=', 'cancelada')
            ->whereNull('ventas.deleted_at')
            ->selectRaw('
                ventas.id_cliente,
                clientes.persona                         AS cliente,
                SUM(ventas.monto_total)                  AS total_vendido,
                SUM(ventas.monto_cobrado)                AS total_cobrado,
                SUM(ventas.monto_total - ventas.monto_cobrado) AS saldo_pendiente,
                COUNT(*)                                 AS cantidad_ventas
            ')
            ->groupBy('ventas.id_cliente', 'clientes.persona')
            ->get()
            ->map(fn($r) => [
                'id_cliente'      => (int) $r->id_cliente,
                'cliente'         => $r->cliente,
                'total_vendido'   => (float) $r->total_vendido,
                'total_cobrado'   => (float) $r->total_cobrado,
                'saldo_pendiente' => (float) $r->saldo_pendiente,
                'cantidad_ventas' => (int)   $r->cantidad_ventas,
            ]);

        return response()->json(['success' => true, 'data' => $resumen]);
    }

    // POST /deudas-clientes/{id_venta}/revertir-precios
    public function revertirPrecios($idVenta): JsonResponse
    {
        $venta = Venta::with('lineas.producto')->findOrFail($idVenta);

        if ($venta->estado_pago === 'pagado') {
            return response()->json(['success' => false, 'message' => 'La venta ya está totalmente cobrada'], 400);
        }

        DB::beginTransaction();
        try {
            foreach ($venta->lineas as $linea) {
                if ($linea->precio_original !== null) {
                    $linea->update(['precio_venta' => $linea->precio_original]);
                }
            }

            $nuevoTotal = $venta->lineas->sum(fn($l) => ($l->precio_original ?? $l->precio_venta) * $l->cantidad);
            $venta->monto_total = $nuevoTotal;

            if ($venta->monto_cobrado >= $nuevoTotal) {
                $venta->estado_pago = 'pagado';
            } elseif ($venta->monto_cobrado > 0) {
                $venta->estado_pago = 'parcial';
            } else {
                $venta->estado_pago = 'pendiente';
            }

            $venta->save();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Precios revertidos al valor original',
                'data'    => $venta->fresh(['cliente', 'pagos', 'lineas.producto']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // POST /deudas-clientes/{id_venta}/actualizar-precios
    public function actualizarPrecios($idVenta): JsonResponse
    {
        $venta = Venta::with('lineas.producto')->findOrFail($idVenta);

        if ($venta->estado_pago === 'pagado') {
            return response()->json(['success' => false, 'message' => 'La venta ya está totalmente cobrada'], 400);
        }

        DB::beginTransaction();
        try {
            foreach ($venta->lineas as $linea) {
                if ($linea->producto) {
                    $linea->update(['precio_venta' => $linea->producto->precio]);
                }
            }

            $nuevoTotal = $venta->lineas->sum(fn($l) => $l->precio_venta * $l->cantidad);
            $venta->monto_total = $nuevoTotal;

            if ($venta->monto_cobrado >= $nuevoTotal) {
                $venta->estado_pago = 'pagado';
            } elseif ($venta->monto_cobrado > 0) {
                $venta->estado_pago = 'parcial';
            } else {
                $venta->estado_pago = 'pendiente';
            }

            $venta->save();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Precios actualizados correctamente',
                'data'    => $venta->fresh(['cliente', 'pagos', 'lineas.producto']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // POST /deudas-clientes/{id_venta}/cobrar
    public function cobrar(Request $request, $idVenta): JsonResponse
    {
        $request->validate([
            'monto'       => 'required|numeric|min:0.01',
            'fecha'       => 'required|date',
            'metodo_pago' => 'nullable|string|max:50',
            'nota'        => 'nullable|string|max:255',
        ]);

        $venta = Venta::findOrFail($idVenta);

        if ($venta->estado_pago === 'pagado') {
            return response()->json(['success' => false, 'message' => 'Esta venta ya está cobrada'], 400);
        }

        $saldo = (float)$venta->monto_total - (float)$venta->monto_cobrado;
        $monto = min((float)$request->monto, $saldo);

        DB::beginTransaction();
        try {
            PagoCliente::create([
                'id_venta'    => $venta->id,
                'id_usuario'  => auth()->user()->nro_usu,
                'monto'       => $monto,
                'fecha'       => substr($request->fecha, 0, 10),
                'metodo_pago' => $request->metodo_pago ?? 'efectivo',
                'nota'        => $request->nota,
            ]);

            $venta->monto_cobrado += $monto;
            $venta->estado_pago    = $venta->monto_cobrado >= $venta->monto_total ? 'pagado' : 'parcial';
            $venta->save();

            // Si cobró en efectivo, sumar a la caja
            if (($request->metodo_pago ?? 'efectivo') === 'efectivo') {
                $turno = $this->turnoService->activo(auth()->user()->nro_usu, auth()->user()->id_sucursal, lock: true);
                if ($turno) {
                    $turno->efectivo_actual  += $monto;
                    $turno->ventas_efectivo  += $monto;
                    $turno->save();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cobro registrado correctamente',
                'data'    => $venta->fresh(['cliente', 'pagos']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
