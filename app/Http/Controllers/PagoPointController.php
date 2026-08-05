<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidaPreciosLinea;
use App\Models\VentaPointIntento;
use App\Services\MercadoPagoTokenService;
use App\Services\TurnoService;
use App\Services\VentaCreacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PagoPointController extends Controller
{
    use ValidaPreciosLinea;

    public function __construct(
        private MercadoPagoTokenService $tokens,
        private VentaCreacionService $ventaCreacionService,
        private TurnoService $turnoService,
    ) {}

    /**
     * Crea un intento de cobro en el dispositivo Point de la empresa. La venta
     * en sí NO se crea acá — se guarda el carrito como snapshot y recién se
     * crea (con stock/caja incluidos) cuando el webhook confirma el pago.
     */
    public function crearIntento(Request $request): JsonResponse
    {
        // Point deshabilitado temporalmente a pedido, vía POINT_HABILITADO en
        // .env (default false si no está seteada) — el frontend ya oculta el
        // botón y la config de dispositivo, esto es la segunda barrera por si
        // llega una request directa. No toca QR ni el estado/cancelación de
        // intentos ya creados antes de este cambio. Para reactivarlo: poner
        // POINT_HABILITADO=true en .env de este backend (no hace falta redeploy
        // de código, pero si el config está cacheado hace falta `config:clear`).
        if (!env('POINT_HABILITADO', false)) {
            return response()->json(['success' => false, 'message' => 'Point está deshabilitado temporalmente'], 503);
        }

        $request->validate([
            'numero_ticket'        => 'nullable|string|max:50',
            'id_cliente'           => 'nullable|exists:clientes,id',
            'fecha'                => 'required|date',
            'hora'                 => 'nullable|string|max:20',
            'lineas'               => 'required|array|min:1',
            'lineas.*.id_producto' => 'nullable|exists:productos,id',
            'lineas.*.nombre'      => 'required_without:lineas.*.id_producto|nullable|string|max:255',
            'lineas.*.precio_venta'=> 'required|numeric|min:0',
            'lineas.*.cantidad'    => 'required|integer|min:1',
        ]);

        $user    = auth('api')->user();
        $empresa = $user->empresa;

        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada'], 400);
        }

        if ($resp = $this->precioLineasSinPermiso($request->lineas, $empresa->id)) {
            return $resp;
        }

        $conexion = $empresa->mercadoPagoConexion;
        if (!$conexion || !$conexion->point_device_id) {
            return response()->json(['success' => false, 'message' => 'Conectá tu cuenta de Mercado Pago y elegí un dispositivo Point en Configuración'], 400);
        }

        $turnoActivo = $this->turnoService->activo($user->nro_usu, $user->id_sucursal);
        if (!$turnoActivo) {
            return response()->json(['success' => false, 'message' => 'No podés cobrar sin abrir la caja primero'], 422);
        }

        $token = $this->tokens->obtenerTokenValido($empresa);
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'No se pudo obtener un token válido de Mercado Pago. Reconectá tu cuenta.'], 400);
        }

        $monto = collect($request->lineas)->sum(fn($l) => $l['precio_venta'] * $l['cantidad']);

        $intento = VentaPointIntento::create([
            'empresa_id'    => $empresa->id,
            'id_usuario'    => $user->nro_usu,
            'id_turno'      => $turnoActivo->id,
            'monto'         => $monto,
            'estado'        => 'pendiente',
            'venta_payload' => [
                'numero_ticket' => $request->numero_ticket,
                'id_cliente'    => $request->id_cliente,
                'id_usuario'    => $user->nro_usu,
                'estado'        => 'confirmada',
                'fecha'         => $request->fecha,
                'hora'          => $request->hora,
                'metodo_pago'   => 'point',
                'lineas'        => $request->lineas,
            ],
        ]);

        try {
            // NOTA: verificar este endpoint/payload contra la documentación vigente de
            // Mercado Pago antes de salir a producción (Point Integration API vs. la
            // Orders API nueva — ver plan de implementación).
            $response = Http::withToken($token)
                ->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
                ->post("https://api.mercadopago.com/point/integration-api/devices/{$conexion->point_device_id}/payment-intents", [
                    'amount'      => (int) round($monto * 100),
                    'description' => "Venta #{$intento->id}",
                    'additional_info' => ['external_reference' => (string) $intento->id],
                ]);

            if (!$response->successful()) {
                Log::warning('PagoPointController::crearIntento fallo al crear el intento en MP', [
                    'intento_id' => $intento->id,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);
                $intento->update(['estado' => 'rechazado']);
                return response()->json(['success' => false, 'message' => 'No se pudo iniciar el cobro en el Point'], 502);
            }

            $intento->update(['mp_intento_id' => $response->json('id')]);

            return response()->json(['success' => true, 'intento_id' => $intento->id]);
        } catch (\Exception $e) {
            Log::warning('PagoPointController::crearIntento excepción: ' . $e->getMessage());
            $intento->update(['estado' => 'rechazado']);
            return response()->json(['success' => false, 'message' => 'Error al iniciar el cobro en el Point'], 500);
        }
    }

    /**
     * Crea un intento de cobro por QR dinámico (no requiere dispositivo físico,
     * solo la cuenta de MP conectada). Mismo patrón que crearIntento(): la venta
     * se crea recién cuando el webhook confirma el pago.
     */
    public function crearIntentoQr(Request $request): JsonResponse
    {
        $request->validate([
            'numero_ticket'        => 'nullable|string|max:50',
            'id_cliente'           => 'nullable|exists:clientes,id',
            'fecha'                => 'required|date',
            'hora'                 => 'nullable|string|max:20',
            'lineas'               => 'required|array|min:1',
            'lineas.*.id_producto' => 'nullable|exists:productos,id',
            'lineas.*.nombre'      => 'required_without:lineas.*.id_producto|nullable|string|max:255',
            'lineas.*.precio_venta'=> 'required|numeric|min:0',
            'lineas.*.cantidad'    => 'required|integer|min:1',
        ]);

        $user    = auth('api')->user();
        $empresa = $user->empresa;

        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada'], 400);
        }

        if ($resp = $this->precioLineasSinPermiso($request->lineas, $empresa->id)) {
            return $resp;
        }

        $conexion = $empresa->mercadoPagoConexion;
        if (!$conexion) {
            return response()->json(['success' => false, 'message' => 'Conectá tu cuenta de Mercado Pago en Configuración'], 400);
        }

        $turnoActivo = $this->turnoService->activo($user->nro_usu, $user->id_sucursal);
        if (!$turnoActivo) {
            return response()->json(['success' => false, 'message' => 'No podés cobrar sin abrir la caja primero'], 422);
        }

        $token = $this->tokens->obtenerTokenValido($empresa);
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'No se pudo obtener un token válido de Mercado Pago. Reconectá tu cuenta.'], 400);
        }

        $monto = collect($request->lineas)->sum(fn($l) => $l['precio_venta'] * $l['cantidad']);

        $intento = VentaPointIntento::create([
            'empresa_id'    => $empresa->id,
            'tipo'          => 'qr',
            'id_usuario'    => $user->nro_usu,
            'id_turno'      => $turnoActivo->id,
            'monto'         => $monto,
            'estado'        => 'pendiente',
            'venta_payload' => [
                'numero_ticket' => $request->numero_ticket,
                'id_cliente'    => $request->id_cliente,
                'id_usuario'    => $user->nro_usu,
                'estado'        => 'confirmada',
                'fecha'         => $request->fecha,
                'hora'          => $request->hora,
                'metodo_pago'   => 'qr',
                'lineas'        => $request->lineas,
            ],
        ]);

        try {
            // NOTA: verificar este endpoint/payload contra la documentación vigente de
            // Mercado Pago antes de salir a producción (Orders API — "type": "qr").
            $response = Http::withToken($token)
                ->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
                ->post('https://api.mercadopago.com/v1/orders', [
                    'type'                => 'qr',
                    'total_amount'        => number_format($monto, 2, '.', ''),
                    'external_reference'  => (string) $intento->id,
                    'description'         => "Venta #{$intento->id}",
                    'config' => [
                        'qr' => [
                            'external_pos_id' => "kamex-empresa-{$empresa->id}",
                            'mode'            => 'dynamic',
                        ],
                    ],
                    'transactions' => [
                        'payments' => [['amount' => number_format($monto, 2, '.', '')]],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('PagoPointController::crearIntentoQr fallo al crear la orden en MP', [
                    'intento_id' => $intento->id,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);
                $intento->update(['estado' => 'rechazado']);
                return response()->json(['success' => false, 'message' => 'No se pudo generar el QR'], 502);
            }

            $qrData = $response->json('type_response.qr_data') ?? $response->json('qr_data');
            $intento->update(['mp_intento_id' => $response->json('id')]);

            return response()->json(['success' => true, 'intento_id' => $intento->id, 'qr_data' => $qrData]);
        } catch (\Exception $e) {
            Log::warning('PagoPointController::crearIntentoQr excepción: ' . $e->getMessage());
            $intento->update(['estado' => 'rechazado']);
            return response()->json(['success' => false, 'message' => 'Error al generar el QR'], 500);
        }
    }

    /**
     * Mercado Pago llama acá cuando cambia el estado de un intento de pago del
     * Point o de una orden de QR — ruta pública, sin JWT. Siempre responde 200
     * rápido para que MP no reintente (mismo patrón que SubscripcionController::webhook).
     *
     * A diferencia de SubscripcionController::webhook, acá NO se valida la firma
     * x-signature (VerifiesMercadoPagoWebhook) todavía — no está confirmado contra
     * la documentación/sandbox vigente si las notificaciones de Point/Orders la
     * incluyen igual que las de payment (ver la NOTA de crearIntento/crearIntentoQr
     * de más arriba). Igual queda protegido de reprocesar un pago dos veces porque
     * solo actúa sobre intentos en estado 'pendiente'.
     */
    public function webhook(Request $request): JsonResponse
    {
        try {
            $mpIntentoId = $request->input('data.id') ?? $request->input('resource') ?? $request->query('id') ?? $request->query('data.id');
            if (!$mpIntentoId) {
                return response()->json(['ok' => true]);
            }

            $intento = VentaPointIntento::withoutGlobalScope('tenant')
                ->where('mp_intento_id', $mpIntentoId)
                ->where('estado', 'pendiente')
                ->first();

            if (!$intento) {
                return response()->json(['ok' => true]);
            }

            $empresa = $intento->empresa;
            $token   = $empresa ? $this->tokens->obtenerTokenValido($empresa) : null;
            if (!$token) {
                return response()->json(['ok' => true]);
            }

            if ($intento->tipo === 'qr') {
                $this->procesarConfirmacionQr($intento, $token, $mpIntentoId);
            } else {
                $this->procesarConfirmacionPoint($intento, $token, $mpIntentoId);
            }
        } catch (\Exception $e) {
            Log::warning('PagoPointController::webhook excepción: ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    private function procesarConfirmacionPoint(VentaPointIntento $intento, string $token, string $mpIntentoId): void
    {
        $response = Http::withToken($token)
            ->get("https://api.mercadopago.com/point/integration-api/payment-intents/{$mpIntentoId}");

        if (!$response->successful()) return;

        $state = $response->json('state');

        if ($state === 'FINISHED') {
            $this->confirmarVenta($intento);
        } elseif (in_array($state, ['CANCELED', 'ERROR'])) {
            $intento->update(['estado' => $state === 'CANCELED' ? 'cancelado' : 'rechazado']);
        }
    }

    private function procesarConfirmacionQr(VentaPointIntento $intento, string $token, string $mpIntentoId): void
    {
        // NOTA: Mercado Pago no publica un enum cerrado de valores de "status" para
        // Orders API en la documentación pública — verificar contra el sandbox real
        // antes de producción. Por eso también se chequea el estado de cada pago
        // dentro de transactions.payments como respaldo.
        $response = Http::withToken($token)->get("https://api.mercadopago.com/v1/orders/{$mpIntentoId}");

        if (!$response->successful()) return;

        $status = $response->json('status');
        $aprobado = in_array($status, ['processed', 'paid'])
            || collect($response->json('transactions.payments', []))->contains(fn($p) => ($p['status'] ?? null) === 'approved');

        if ($aprobado) {
            $this->confirmarVenta($intento);
        } elseif (in_array($status, ['expired', 'canceled', 'cancelled'])) {
            $intento->update(['estado' => 'cancelado']);
        }
    }

    private function confirmarVenta(VentaPointIntento $intento): void
    {
        DB::beginTransaction();
        try {
            $venta = $this->ventaCreacionService->crear(
                $intento->venta_payload,
                $intento->empresa_id,
                $intento->id_turno
            );
            $intento->update(['estado' => 'aprobado', 'id_venta' => $venta->id]);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::warning('PagoPointController::confirmarVenta fallo al crear la venta confirmada: ' . $e->getMessage(), [
                'intento_id' => $intento->id,
            ]);
            $intento->update(['estado' => 'rechazado']);
        }
    }

    public function estado($id): JsonResponse
    {
        $intento = VentaPointIntento::findOrFail($id);

        return response()->json([
            'success' => true,
            'estado'  => $intento->estado,
            'id_venta'=> $intento->id_venta,
        ]);
    }

    public function cancelar($id): JsonResponse
    {
        $intento = VentaPointIntento::findOrFail($id);

        if ($intento->estado !== 'pendiente') {
            return response()->json(['success' => true, 'estado' => $intento->estado]);
        }

        $empresa = $intento->empresa;
        $token   = $empresa ? $this->tokens->obtenerTokenValido($empresa) : null;

        if ($token && $intento->mp_intento_id) {
            try {
                if ($intento->tipo === 'qr') {
                    Http::withToken($token)->post("https://api.mercadopago.com/v1/orders/{$intento->mp_intento_id}/cancel");
                } else {
                    Http::withToken($token)->delete("https://api.mercadopago.com/point/integration-api/payment-intents/{$intento->mp_intento_id}");
                }
            } catch (\Exception $e) {
                Log::warning('PagoPointController::cancelar excepción al cancelar en MP: ' . $e->getMessage());
            }
        }

        $intento->update(['estado' => 'cancelado']);

        return response()->json(['success' => true, 'estado' => 'cancelado']);
    }
}
