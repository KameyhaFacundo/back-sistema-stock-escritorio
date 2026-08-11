<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\MovimientoCaja;
use App\Models\PagoCliente;
use App\Jobs\SendComprobantePagoJob;
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
            ->get();

        // Cuánto de ese total_cobrado fue por cada método — todas las ventas de
        // este resumen arrancaron en "fiado" (única forma de que estado_pago no
        // sea ya "pagado" desde el alta, ver VentaCreacionService), así que todo
        // su monto_cobrado viene de pagos_cliente, nada que sumar de la venta en sí.
        $cobradoPorMetodo = DB::table('pagos_cliente')
            ->join('ventas', 'pagos_cliente.id_venta', '=', 'ventas.id')
            ->where('ventas.empresa_id', $empresaId)
            ->whereIn('ventas.estado_pago', ['pendiente', 'parcial'])
            ->where('ventas.estado', '!=', 'cancelada')
            ->whereNull('ventas.deleted_at')
            ->selectRaw('ventas.id_cliente, pagos_cliente.metodo_pago, SUM(pagos_cliente.monto) as total')
            ->groupBy('ventas.id_cliente', 'pagos_cliente.metodo_pago')
            ->get()
            ->groupBy('id_cliente');

        $resumen = $resumen->map(fn($r) => [
            'id_cliente'        => (int) $r->id_cliente,
            'cliente'           => $r->cliente,
            'total_vendido'     => (float) $r->total_vendido,
            'total_cobrado'     => (float) $r->total_cobrado,
            'saldo_pendiente'   => (float) $r->saldo_pendiente,
            'cantidad_ventas'   => (int)   $r->cantidad_ventas,
            'cobrado_por_metodo' => ($cobradoPorMetodo->get($r->id_cliente) ?? collect())
                ->mapWithKeys(fn($p) => [$p->metodo_pago ?? 'efectivo' => (float) $p->total]),
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
            // Antes era opcional — un cobro sin ninguna referencia (dónde/cómo
            // se cobró) es mucho más fácil de inventar. No evita que alguien
            // mienta, pero sube el costo de mentir y deja algo puntual para
            // preguntar después si algo no cierra.
            'nota'        => 'required|string|max:255',
        ]);

        $venta = Venta::with('cliente')->findOrFail($idVenta);

        if ($venta->estado_pago === 'pagado') {
            return response()->json(['success' => false, 'message' => 'Esta venta ya está cobrada'], 400);
        }

        $saldo = (float)$venta->monto_total - (float)$venta->monto_cobrado;
        $monto = min((float)$request->monto, $saldo);
        $metodoPago = $request->metodo_pago ?? 'efectivo';

        DB::beginTransaction();
        try {
            PagoCliente::create([
                'id_venta'    => $venta->id,
                'id_usuario'  => auth()->user()->nro_usu,
                'monto'       => $monto,
                'fecha'       => substr($request->fecha, 0, 10),
                'metodo_pago' => $metodoPago,
                'nota'        => $request->nota,
            ]);

            $venta->monto_cobrado += $monto;
            $venta->estado_pago    = $venta->monto_cobrado >= $venta->monto_total ? 'pagado' : 'parcial';
            $venta->save();

            // Efectivo y transferencia dejan un renglón en Movimientos — plata
            // que debería reflejarse en el cajón físico o en la cuenta del
            // negocio. Tarjeta/QR no: van directo al procesador de pago, sin
            // "arqueo" posible de nuestro lado (metodo de movimientos_caja
            // solo admite efectivo/transferencia, ver esa migración). Antes
            // esto solo pasaba para efectivo — una transferencia cobrada acá
            // no dejaba NINGÚN rastro en Caja, así que declarar en falso
            // "transferencia" para un cobro que en realidad fue en efectivo
            // era invisible: no faltaba nada en ningún arqueo.
            if (in_array($metodoPago, ['efectivo', 'transferencia'], true)) {
                $turno = $this->turnoService->activo(auth()->user()->nro_usu, auth()->user()->id_sucursal, lock: true);
                if ($turno) {
                    if ($metodoPago === 'efectivo') {
                        $turno->efectivo_actual  += $monto;
                        $turno->ventas_efectivo  += $monto;
                        $turno->save();
                    }

                    $nombreCliente = $venta->cliente?->persona;
                    MovimientoCaja::create([
                        'id_turno' => $turno->id,
                        'tipo'     => 'ingreso',
                        'metodo'   => $metodoPago,
                        'monto'    => $monto,
                        'motivo'   => $nombreCliente
                            ? "Cobro de deuda #{$venta->id} — {$nombreCliente}"
                            : "Cobro de deuda #{$venta->id}",
                        'hora'     => now()->format('H:i'),
                    ]);
                }
            }

            DB::commit();

            // Recibo automático por mail — no depende de que el empleado se
            // acuerde de avisarle al cliente (ni de que quiera hacerlo). Si el
            // cliente nunca pagó esto, acá es donde se entera sin que nadie
            // del local tenga que decírselo. Se manda DESPUÉS del commit (no
            // tiene que poder tumbar el cobro si el mail falla) y solo si hay
            // email cargado — no bloquea el cobro si no lo tiene.
            if ($venta->cliente?->email) {
                SendComprobantePagoJob::dispatch(
                    $venta->cliente->email,
                    $venta->cliente->persona,
                    $monto,
                    $metodoPago,
                    substr($request->fecha, 0, 10),
                    max(0, (float) $venta->monto_total - (float) $venta->monto_cobrado),
                    auth()->user()->empresa?->nombre ?? config('app.name'),
                    $venta->id,
                );
            }

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
