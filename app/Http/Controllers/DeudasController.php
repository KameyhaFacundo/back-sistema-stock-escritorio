<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use App\Models\MovimientoCaja;
use App\Models\PagoProveedor;
use App\Services\TurnoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeudasController extends Controller
{
    public function __construct(private TurnoService $turnoService) {}

    // GET /deudas — listado de compras con deuda pendiente o parcial
    public function index(Request $request): JsonResponse
    {
        $query = Compra::with(['proveedor', 'pagos'])
            ->whereIn('estado_deuda', ['pendiente', 'parcial'])
            ->orderBy('fecha', 'desc');

        if ($request->id_proveedor) {
            $query->where('id_proveedor', $request->id_proveedor);
        }

        $deudas = $query->paginate($request->per_page ?? 50);

        // Totales globales — DB::table (no el modelo Compra) para no depender de
        // que ningún alias futuro choque con un accessor de Compra (ver resumen()).
        // where() (no when()) a propósito: cuando $empresaIdTotal es null (usuario
        // sin empresa asignada) tiene que dar 0 filas, no el total de TODAS las
        // empresas — when() con un valor falsy directamente omite el filtro.
        $empresaIdTotal = auth('api')->user()?->empresa_id;
        $totalDeuda = DB::table('compras')
            ->where('empresa_id', $empresaIdTotal)
            ->whereIn('estado_deuda', ['pendiente', 'parcial'])
            ->whereNull('deleted_at')
            ->selectRaw('SUM(monto_total - monto_pagado) as total')
            ->value('total') ?? 0;

        return response()->json([
            'success'     => true,
            'data'        => $deudas,
            'total_deuda' => (float) $totalDeuda,
        ]);
    }

    // GET /deudas/resumen — deuda total agrupada por proveedor
    public function resumen(): JsonResponse
    {
        // OJO: se usa DB::table (no el modelo Compra) a propósito — Compra
        // define un accessor getSaldoPendienteAttribute() que pisa el alias
        // "saldo_pendiente" de este SELECT agregado y siempre devuelve 0,
        // porque en una fila agrupada $this->monto_total/monto_pagado no
        // existen. Bypasear Eloquent evita ese choque. Como no pasa por el
        // modelo, hay que filtrar por empresa_id a mano (HasTenant no aplica).
        // where() (no when()): un $empresaId null tiene que dar 0 filas, no
        // saltarse el filtro y agregar la deuda de TODAS las empresas.
        $empresaId = auth('api')->user()?->empresa_id;

        $resumen = DB::table('compras')
            ->join('proveedores', 'compras.id_proveedor', '=', 'proveedores.id')
            ->where('compras.empresa_id', $empresaId)
            ->whereIn('compras.estado_deuda', ['pendiente', 'parcial'])
            ->whereNull('compras.deleted_at')
            ->selectRaw('
                compras.id_proveedor,
                proveedores.persona                              AS proveedor,
                SUM(compras.monto_total)                         AS total_comprado,
                SUM(compras.monto_pagado)                        AS total_pagado,
                SUM(compras.monto_total - compras.monto_pagado)  AS saldo_pendiente,
                COUNT(*)                                         AS cantidad_compras
            ')
            ->groupBy('compras.id_proveedor', 'proveedores.persona')
            ->get()
            ->map(fn($r) => [
                'id_proveedor'     => (int) $r->id_proveedor,
                'proveedor'        => $r->proveedor,
                'total_comprado'   => (float) $r->total_comprado,
                'total_pagado'     => (float) $r->total_pagado,
                'saldo_pendiente'  => (float) $r->saldo_pendiente,
                'cantidad_compras' => (int) $r->cantidad_compras,
            ]);

        return response()->json(['success' => true, 'data' => $resumen]);
    }

    // POST /deudas/{id_compra}/pagar — registrar un pago contra una compra
    public function pagar(Request $request, $idCompra): JsonResponse
    {
        $request->validate([
            'monto'       => 'required|numeric|min:0.01',
            'fecha'       => 'required|date',
            'metodo_pago' => 'nullable|string|max:50',
            'nota'        => 'nullable|string|max:255',
        ]);

        $compra = Compra::findOrFail($idCompra);

        if ($compra->estado_deuda === 'pagado') {
            return response()->json(['success' => false, 'message' => 'Esta compra ya está pagada'], 400);
        }

        $saldo = (float)$compra->monto_total - (float)$compra->monto_pagado;
        $monto = min((float)$request->monto, $saldo);

        DB::beginTransaction();
        try {
            PagoProveedor::create([
                'id_compra'   => $compra->id,
                'id_usuario'  => auth()->user()->nro_usu,
                'monto'       => $monto,
                'fecha'       => substr($request->fecha, 0, 10),
                'metodo_pago' => $request->metodo_pago ?? 'efectivo',
                'nota'        => $request->nota,
            ]);

            $compra->monto_pagado += $monto;
            $compra->estado_deuda = $compra->monto_pagado >= $compra->monto_total ? 'pagado' : 'parcial';
            $compra->save();

            // Si el pago fue en efectivo, descontar de la caja — y dejar un
            // renglón en Movimientos (antes no quedaba ningún rastro visible
            // de este pago, a diferencia de una compra pagada al contado, que
            // sí genera un movimiento vía ajustarCajaCompra()).
            if (($request->metodo_pago ?? 'efectivo') === 'efectivo') {
                $turno = $this->turnoService->activo(auth()->user()->nro_usu, auth()->user()->id_sucursal, lock: true);
                if ($turno) {
                    $turno->efectivo_actual = max(0, $turno->efectivo_actual - $monto);
                    $turno->save();

                    $nombreProveedor = $compra->loadMissing('proveedor')->proveedor?->persona;
                    MovimientoCaja::create([
                        'id_turno' => $turno->id,
                        'tipo'     => 'egreso',
                        'monto'    => $monto,
                        'motivo'   => $nombreProveedor
                            ? "Pago de deuda #{$compra->id} — {$nombreProveedor}"
                            : "Pago de deuda #{$compra->id}",
                        'hora'     => now()->format('H:i'),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pago registrado correctamente',
                'data'    => $compra->fresh(['proveedor', 'pagos']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
