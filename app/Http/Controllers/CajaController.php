<?php

namespace App\Http\Controllers;

use App\Models\Turno;
use App\Models\MovimientoCaja;
use App\Models\Venta;
use App\Services\TurnoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CajaController extends Controller
{
    public function __construct(private TurnoService $turnoService) {}

    private function nowFecha(): string
    {
        return now()->format('Y-m-d');
    }

    private function nowHora(): string
    {
        return now()->format('H:i');
    }

    /**
     * A diferencia de "ventas_efectivo" (columna del turno, actualizada
     * incrementalmente porque alimenta el arqueo de efectivo físico), tarjeta,
     * transferencia, qr y fiado no tocan plata en la caja — no hace falta
     * llevar un contador propio, se calculan al vuelo sumando las ventas del
     * turno. Se excluyen las anuladas, y monto_total ya queda neto de
     * cualquier devolución parcial (DevolucionVentaService lo descuenta ahí
     * mismo). El frontend decide cuáles de las 5 mostrar según
     * empresa.arqueo_metodos_visibles — acá siempre se devuelven todas.
     */
    private function agregarTotalesPorMetodo(?Turno $turno): ?Turno
    {
        if (!$turno) return $turno;

        $sums = Venta::where('id_turno', $turno->id)
            ->where('estado', '!=', 'cancelada')
            ->selectRaw('metodo_pago, SUM(monto_total) as total')
            ->groupBy('metodo_pago')
            ->pluck('total', 'metodo_pago');

        $turno->setAttribute('ventas_tarjeta', (float) ($sums['tarjeta'] ?? 0));
        $turno->setAttribute('ventas_transferencia', (float) ($sums['transferencia'] ?? 0));
        $turno->setAttribute('ventas_qr', (float) ($sums['qr'] ?? 0));
        $turno->setAttribute('ventas_fiado', (float) ($sums['fiado'] ?? 0));

        return $turno;
    }

    // GET /caja/turno-activo
    public function turnoActivo(): JsonResponse
    {
        $turno = Turno::with('movimientos')
            ->withCount('ventas')
            ->withSum('ventas', 'monto_total')
            ->where('id_usuario', auth()->user()->nro_usu)
            ->where('estado', 'abierta')
            ->where('id_sucursal', auth()->user()->id_sucursal)
            ->latest()
            ->first();

        $this->agregarTotalesPorMetodo($turno);

        return response()->json([
            'success' => true,
            'data'    => $turno,
        ]);
    }

    // GET /caja/historial
    // Muestra los turnos de TODA la sucursal activa (no solo los del usuario
    // logueado), para que se pueda revisar qué cajero abrió/cerró cada caja.
    public function historial(Request $request): JsonResponse
    {
        $turnos = Turno::with(['movimientos', 'usuario:nro_usu,des_usu'])
            ->withCount('ventas')
            ->withSum('ventas', 'monto_total')
            ->where('id_sucursal', auth()->user()->id_sucursal)
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => $turnos,
        ]);
    }

    // POST /caja/abrir
    public function abrir(Request $request): JsonResponse
    {
        $request->validate([
            'monto_inicial' => 'required|numeric|min:0',
        ]);

        $idSucursal = auth()->user()->id_sucursal;
        if (!$idSucursal) {
            return response()->json(['success' => false, 'message' => 'Tu usuario no tiene una sucursal asignada'], 422);
        }

        // Cerrar cualquier turno abierto previo del usuario (en cualquier sucursal)
        Turno::where('id_usuario', auth()->user()->nro_usu)
             ->where('estado', 'abierta')
             ->update(['estado' => 'cerrada', 'hora_cierre' => $this->nowHora()]);

        DB::beginTransaction();
        try {
            $turno = Turno::create([
                'id_usuario'    => auth()->user()->nro_usu,
                'id_sucursal'   => $idSucursal,
                'estado'        => 'abierta',
                'fecha'         => $this->nowFecha(),
                'hora_apertura' => $this->nowHora(),
                'monto_inicial' => $request->monto_inicial,
                'efectivo_actual' => $request->monto_inicial,
                'ventas_efectivo' => 0,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Caja abierta correctamente',
                'data'    => $turno->load('movimientos'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // PUT /caja/cerrar
    public function cerrar(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            // Lock: "esperado" se snapshotea de efectivo_actual en esta misma
            // transacción — sin el lock, una venta o movimiento concurrente podía
            // colarse entre la lectura y el cierre y quedar afuera del arqueo.
            $turno = $this->turnoService->activo(auth()->user()->nro_usu, auth()->user()->id_sucursal, lock: true);
            if (!$turno) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'No hay una caja abierta'], 404);
            }

            // "Esperado" es el efectivo_actual justo antes de cerrar — se calculó en vivo
            // durante todo el turno (ventas + ingresos/egresos manuales). Se snapshotea acá
            // para que el arqueo sea ciego: el cajero nunca ve este número antes de cargar
            // lo que contó a mano.
            $esperado   = (float) $turno->efectivo_actual;
            $montoFinal = $request->monto_contado ?? $esperado;

            $turno->update([
                'estado'         => 'cerrada',
                'hora_cierre'    => $this->nowHora(),
                'monto_final'    => $montoFinal,
                'monto_esperado' => $esperado,
                'diferencia'     => $montoFinal - $esperado,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Caja cerrada correctamente',
                'data'    => $this->agregarTotalesPorMetodo($turno->fresh('movimientos')),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // POST /caja/movimiento
    public function agregarMovimiento(Request $request): JsonResponse
    {
        $request->validate([
            'tipo'   => 'required|in:ingreso,egreso',
            'metodo' => 'nullable|in:efectivo,transferencia',
            'monto'  => 'required|numeric|min:0.01',
            'motivo' => 'nullable|string|max:255',
        ]);

        $metodo = $request->metodo ?? 'efectivo';

        DB::beginTransaction();
        try {
            $turno = $this->turnoService->activo(auth()->user()->nro_usu, auth()->user()->id_sucursal, lock: true);
            if (!$turno) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'No hay una caja abierta'], 404);
            }

            $mov = MovimientoCaja::create([
                'id_turno' => $turno->id,
                'tipo'     => $request->tipo,
                'metodo'   => $metodo,
                'monto'    => $request->monto,
                'motivo'   => $request->motivo,
                'hora'     => $this->nowHora(),
            ]);

            // Una transferencia no es plata física — no toca el efectivo que
            // el arqueo de cierre va a pedir contar a mano.
            if ($metodo === 'efectivo') {
                $turno->efectivo_actual = $request->tipo === 'ingreso'
                    ? $turno->efectivo_actual + $request->monto
                    : $turno->efectivo_actual - $request->monto;
                $turno->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Movimiento registrado',
                'data'    => ['movimiento' => $mov, 'turno' => $turno->fresh('movimientos')],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
