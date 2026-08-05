<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPlanLimits;
use App\Models\DevolucionVenta;
use App\Models\Empresa;
use App\Models\Factura;
use App\Services\ArcaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FacturaController extends Controller
{
    use ChecksPlanLimits;

    public function emitir(Request $request): JsonResponse
    {
        $empresa = auth()->user()->empresa;
        if ($empresa && ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'facturacion',
            'La facturación electrónica (ARCA) está disponible desde el plan Pro. Actualizá tu plan para emitir comprobantes.'
        ))) {
            return $resp;
        }

        if ($empresa && !$empresa->arca) {
            return response()->json([
                'success' => false,
                'message' => 'La facturación electrónica está desactivada. Activala en Configuración → Facturación.',
            ], 403);
        }

        $request->validate([
            'id_venta'          => 'nullable|integer',
            'total'             => 'required|numeric|min:0',
            'items'             => 'required|array|min:1',
            'items.*.precio'    => 'required|numeric|min:0',
            'items.*.cantidad'  => 'required|numeric|min:1',
            'tipo_comprobante'  => 'nullable|in:1,6,11',
            'tipo_documento'    => 'nullable|integer',
            'numero_documento'  => 'nullable|string',
            'cliente_nombre'    => 'nullable|string',
            'fecha'             => 'nullable|date_format:Ymd',
        ]);

        $user = auth()->user();

        // Lock de la empresa mientras dura todo el flujo (incluida la llamada a
        // ARCA): en modo de prueba (sin certificado real todavía) el "próximo
        // número" se calcula acá mismo leyendo la última factura local, sin
        // esto dos emisiones simultáneas podían leer el mismo último número y
        // guardar dos facturas con el mismo folio. En producción real el
        // número lo asigna ARCA de su lado (autoritativo), esto solo cierra el
        // hueco del modo de prueba.
        return DB::transaction(function () use ($request, $user) {
            $empresa = Empresa::whereKey($user->empresa_id)->lockForUpdate()->first();

            if ($empresa->facturas_disponibles <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No te quedan facturas disponibles. Comprá un pack para seguir facturando.',
                    'sin_facturas_disponibles' => true,
                ], 403);
            }

            $arca    = new ArcaService($empresa);

            $ultima = Factura::where('empresa_id', $user->empresa_id)
                ->where('punto_venta', $empresa->arca_punto_venta)
                ->orderByDesc('numero')
                ->first();

            $datos = [
                'punto_venta'      => $empresa->arca_punto_venta ?? 1,
                'tipo_comprobante' => $request->tipo_comprobante ?? ArcaService::TIPO_FACTURA_B,
                'tipo_documento'   => $request->tipo_documento ?? ($request->numero_documento ? ArcaService::DOC_CUIT : ArcaService::DOC_CONSUMIDOR_FINAL),
                'numero_documento' => $request->numero_documento ?? '0',
                'fecha'            => $request->fecha ?? date('Ymd'),
                'total'            => $request->total,
                'neto'             => round($request->total / 1.21, 2),
                'iva'              => round($request->total - ($request->total / 1.21), 2),
                'items'            => $request->items,
                'ultimo_numero'    => $ultima?->numero ?? 0,
            ];

            $resultado = $arca->emitirFactura($datos);

            if (empty($resultado['success'])) {
                return response()->json([
                    'success' => false,
                    'errores' => $resultado['errores'] ?? ['Error desconocido al emitir la factura'],
                ], 422);
            }

            // Guardar factura en BD
            $factura = Factura::create([
                'empresa_id'       => $user->empresa_id,
                'id_venta'         => $request->id_venta,
                'id_usuario'       => $user->nro_usu,
                'tipo_comprobante' => $resultado['tipo_comprobante'],
                'punto_venta'      => $resultado['punto_venta'],
                'numero'           => $resultado['numero'],
                'cae'              => $resultado['cae'],
                'vencimiento_cae'  => $resultado['vencimiento_cae'],
                'fecha'            => $resultado['fecha'],
                'total'            => $resultado['total'],
                'neto'             => $datos['neto'],
                'iva'              => $datos['iva'],
                'tipo_documento'   => $datos['tipo_documento'],
                'numero_documento' => $datos['numero_documento'],
                'cliente_nombre'   => $request->cliente_nombre,
                'estado'           => $resultado['modo_prueba'] ?? false ? 'prueba' : 'emitida',
            ]);

            $empresa->decrement('facturas_disponibles');

            return response()->json([
                'success' => true,
                'data'    => $factura,
                'modo_prueba' => $resultado['modo_prueba'] ?? false,
            ]);
        });
    }

    /**
     * Emite una Nota de Crédito contra una factura ya emitida (con CAE real o
     * de prueba) — para cuando se anula o se devuelve (parcial) una venta
     * facturada. El monto puede ser menor al total de la factura (devolución
     * parcial); no se puede acreditar más de lo que la factura todavía tiene
     * sin acreditar, contando notas de crédito previas contra la misma.
     */
    public function emitirNotaCredito(Request $request, $idFactura): JsonResponse
    {
        $empresa = auth()->user()->empresa;
        if ($empresa && ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'facturacion',
            'La facturación electrónica (ARCA) está disponible desde el plan Pro. Actualizá tu plan para emitir comprobantes.'
        ))) {
            return $resp;
        }

        if ($empresa && !$empresa->arca) {
            return response()->json([
                'success' => false,
                'message' => 'La facturación electrónica está desactivada. Activala en Configuración → Facturación.',
            ], 403);
        }

        $request->validate([
            'monto'                => 'required|numeric|min:0.01',
            'id_devolucion_venta'  => 'nullable|integer|exists:devoluciones_venta,id',
        ]);

        $user = auth()->user();

        return DB::transaction(function () use ($request, $user, $idFactura) {
            $empresa = Empresa::whereKey($user->empresa_id)->lockForUpdate()->first();
            $original = Factura::where('empresa_id', $user->empresa_id)->findOrFail($idFactura);

            // La regla `exists:` de la validación no filtra por empresa/venta —
            // se confirma acá antes de guardar el vínculo en la NC.
            $idDevolucionVenta = $request->id_devolucion_venta
                ? DevolucionVenta::where('empresa_id', $user->empresa_id)
                    ->where('id_venta', $original->id_venta)
                    ->whereKey($request->id_devolucion_venta)
                    ->value('id')
                : null;

            $yaAcreditado = (float) $original->notasCredito()->sum('total');
            $disponible   = (float) $original->total - $yaAcreditado;

            if ($request->monto > $disponible + 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => "Esta factura ya tiene {$yaAcreditado} acreditado de {$original->total} — no se puede emitir una nota de crédito por {$request->monto}",
                ], 422);
            }

            $arca = new ArcaService($empresa);
            $tipoNC = ArcaService::tipoNotaCreditoPara($original->tipo_comprobante);

            $ultima = Factura::where('empresa_id', $user->empresa_id)
                ->where('punto_venta', $original->punto_venta)
                ->where('tipo_comprobante', $tipoNC)
                ->orderByDesc('numero')
                ->first();

            $monto = (float) $request->monto;
            $datos = [
                'punto_venta'      => $original->punto_venta,
                'tipo_comprobante' => $tipoNC,
                'tipo_documento'   => $original->tipo_documento,
                'numero_documento' => $original->numero_documento,
                'fecha'            => date('Ymd'),
                'total'            => $monto,
                'neto'             => round($monto / 1.21, 2),
                'iva'              => round($monto - ($monto / 1.21), 2),
                'items'            => [['precio' => $monto, 'cantidad' => 1]],
                'ultimo_numero'    => $ultima?->numero ?? 0,
                'comprobante_asociado' => [
                    'tipo'        => $original->tipo_comprobante,
                    'punto_venta' => $original->punto_venta,
                    'numero'      => $original->numero,
                ],
            ];

            $resultado = $arca->emitirFactura($datos);

            if (empty($resultado['success'])) {
                return response()->json([
                    'success' => false,
                    'errores' => $resultado['errores'] ?? ['Error desconocido al emitir la nota de crédito'],
                ], 422);
            }

            $notaCredito = Factura::create([
                'empresa_id'              => $user->empresa_id,
                'id_venta'                => $original->id_venta,
                'id_comprobante_asociado' => $original->id,
                'id_devolucion_venta'     => $idDevolucionVenta,
                'id_usuario'              => $user->nro_usu,
                'tipo_comprobante'        => $resultado['tipo_comprobante'],
                'punto_venta'             => $resultado['punto_venta'],
                'numero'                  => $resultado['numero'],
                'cae'                     => $resultado['cae'],
                'vencimiento_cae'         => $resultado['vencimiento_cae'],
                'fecha'                   => $resultado['fecha'],
                'total'                   => $resultado['total'],
                'neto'                    => $datos['neto'],
                'iva'                     => $datos['iva'],
                'tipo_documento'          => $original->tipo_documento,
                'numero_documento'        => $original->numero_documento,
                'cliente_nombre'          => $original->cliente_nombre,
                'estado'                  => $resultado['modo_prueba'] ?? false ? 'prueba' : 'emitida',
            ]);

            return response()->json([
                'success'     => true,
                'data'        => $notaCredito,
                'modo_prueba' => $resultado['modo_prueba'] ?? false,
            ]);
        });
    }

    /**
     * Lista las facturas de la empresa del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $facturas = Factura::where('empresa_id', auth()->user()->empresa_id)
            ->when($request->fecha_desde, fn($q) => $q->where('fecha', '>=', $request->fecha_desde))
            ->when($request->fecha_hasta, fn($q) => $q->where('fecha', '<=', $request->fecha_hasta))
            ->orderByDesc('fecha')
            ->orderByDesc('numero')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => $facturas,
        ]);
    }

    /**
     * Consulta una factura específica.
     */
    public function show($id): JsonResponse
    {
        $factura = Factura::where('empresa_id', auth()->user()->empresa_id)
            ->with(['venta', 'comprobanteAsociado', 'notasCredito', 'devolucionVenta.usuario'])
            ->findOrFail($id);

        // Solo tiene sentido "cuánto queda por acreditar" del lado de la
        // factura original, nunca de una nota de crédito (que no tiene NCs
        // propias contra ella).
        if (!$factura->id_comprobante_asociado) {
            $yaAcreditado = (float) $factura->notasCredito->sum('total');
            $factura->setAttribute('ya_acreditado', $yaAcreditado);
            $factura->setAttribute('disponible', (float) $factura->total - $yaAcreditado);
        }

        return response()->json([
            'success' => true,
            'data'    => $factura,
        ]);
    }

    /**
     * Último número de factura emitido para este punto de venta.
     */
    public function ultima(): JsonResponse
    {
        $empresa = auth()->user()->empresa;
        $ultima = Factura::where('empresa_id', auth()->user()->empresa_id)
            ->where('punto_venta', $empresa->arca_punto_venta)
            ->orderByDesc('numero')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $ultima ? [
                'numero'      => $ultima->numero,
                'comprobante' => $ultima->numero_completo,
                'fecha'       => $ultima->fecha,
                'cae'         => $ultima->cae,
            ] : null,
        ]);
    }

    /**
     * Devuelve si ARCA está configurada.
     */
    public function estado(): JsonResponse
    {
        $empresa = auth()->user()->empresa;
        $arca    = new ArcaService($empresa);

        return response()->json([
            'success'      => true,
            'disponible'   => $arca->disponible(),
            'homologacion' => (bool) $empresa->arca_homologacion,
            'cuit'         => $empresa->cuit ? substr($empresa->cuit, 0, 2) . '...' : null,
            'punto_venta'  => $empresa->arca_punto_venta,
            'facturas_disponibles' => $empresa->facturas_disponibles,
        ]);
    }
}
