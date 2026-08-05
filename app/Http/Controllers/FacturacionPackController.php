<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPlanLimits;
use App\Models\Empresa;
use App\Models\PagoFacturacionPack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;

// Compra de packs prepagos de facturación electrónica — add-on separado del
// plan (ver config/planes.php 'packs_facturacion'). El cobro reutiliza la
// misma cuenta de Mercado Pago de la plataforma que SubscripcionController;
// la confirmación llega al mismo webhook (SubscripcionController::webhook),
// que distingue este caso por el prefijo "pack|" en el external_reference.
class FacturacionPackController extends Controller
{
    use ChecksPlanLimits;

    public function crear(Request $request): JsonResponse
    {
        $request->validate([
            'pack' => 'required|in:500,1000,2000',
        ]);

        $user    = auth('api')->user();
        $empresa = $user->empresa;

        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada.'], 422);
        }

        if ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'facturacion',
            'La facturación electrónica no está disponible en tu plan actual — no tiene sentido comprar facturas todavía.'
        )) {
            return $resp;
        }

        $packData = config('planes.packs_facturacion')[$request->pack];
        $cantidad = $packData['cantidad'];
        $precio   = $packData['precio'];
        $titulo   = "Pack de {$cantidad} facturas electrónicas";

        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

        $accessToken = env('MP_ACCESS_TOKEN');
        if (!$accessToken) {
            return response()->json(['success' => false, 'message' => 'MP_ACCESS_TOKEN no configurado.'], 500);
        }

        MercadoPagoConfig::setAccessToken($accessToken);

        $appUrl      = env('APP_URL', '');
        $isLocalhost = str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1');

        $preferenceData = [
            'items' => [[
                'title'       => $titulo,
                'quantity'    => 1,
                'unit_price'  => (float) $precio,
                'currency_id' => 'ARS',
            ]],
            'back_urls' => [
                'success' => "{$frontendUrl}/pago/exito?tipo=pack&pack={$request->pack}",
                'failure' => "{$frontendUrl}/pago/fallo?tipo=pack&pack={$request->pack}",
                'pending' => "{$frontendUrl}/pago/exito?tipo=pack&pack={$request->pack}&pending=1",
            ],
            'auto_return'          => 'approved',
            'external_reference'   => 'pack|' . $empresa->id . '|' . $request->pack,
            'statement_descriptor' => 'Sistema Stock',
        ];

        if (!$isLocalhost) {
            $preferenceData['notification_url'] = $appUrl . '/api/suscripcion/webhook';
        }

        try {
            $preference = (new PreferenceClient())->create($preferenceData);

            return response()->json([
                'success'    => true,
                'init_point' => $preference->init_point,
            ]);
        } catch (MPApiException $e) {
            $apiResponse = $e->getApiResponse();
            $detail = $apiResponse ? json_encode($apiResponse->getContent()) : $e->getMessage();
            return response()->json([
                'success' => false,
                'message' => 'Error MercadoPago: ' . $detail,
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error inesperado: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Acredita un pack ya pagado (llamado desde SubscripcionController::webhook
     * cuando el external_reference viene con el prefijo "pack|"). Devuelve
     * false si el payment_id ya se acreditó antes (idempotencia de reintentos
     * del webhook de Mercado Pago).
     */
    public function acreditarPack(int $empresaId, string $pack, string $paymentId, ?float $monto): bool
    {
        if (PagoFacturacionPack::where('payment_id', $paymentId)->exists()) {
            return false;
        }

        $packData = config('planes.packs_facturacion')[$pack] ?? null;
        if (!$packData) {
            return false;
        }

        DB::transaction(function () use ($empresaId, $pack, $paymentId, $monto, $packData) {
            $empresa = Empresa::whereKey($empresaId)->lockForUpdate()->first();
            if (!$empresa) {
                return;
            }

            $empresa->update([
                'facturas_disponibles' => $empresa->facturas_disponibles + $packData['cantidad'],
            ]);

            PagoFacturacionPack::create([
                'empresa_id' => $empresaId,
                'pack'       => $pack,
                'cantidad'   => $packData['cantidad'],
                'monto'      => $monto,
                'payment_id' => $paymentId,
            ]);
        });

        return true;
    }
}
