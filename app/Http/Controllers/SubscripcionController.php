<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\VerifiesMercadoPagoWebhook;
use App\Models\Empresa;
use App\Models\PagoSuscripcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;

class SubscripcionController extends Controller
{
    use VerifiesMercadoPagoWebhook;

    private array $planes = [
        'esencial' => ['nombre' => 'Plan Esencial', 'precioMes' => 100, 'precioAnual' => 28000],
        'pro'      => ['nombre' => 'Plan Pro',      'precioMes' => 47000, 'precioAnual' => 37600],
        'ia'       => ['nombre' => 'Plan IA',       'precioMes' => 60000, 'precioAnual' => 48000],
    ];

    public function crear(Request $request): JsonResponse
    {
        $request->validate([
            'plan'  => 'required|in:esencial,pro,ia',
            'ciclo' => 'required|in:mensual,anual',
        ]);

        $planData = $this->planes[$request->plan];
        $ciclo    = $request->ciclo;
        $precio   = $ciclo === 'anual'
            ? $planData['precioAnual'] * 12
            : $planData['precioMes'];
        $titulo   = $planData['nombre'] . ' — ' . ($ciclo === 'anual' ? 'Anual' : 'Mensual');

        $user        = auth('api')->user();
        $empresa     = $user->empresa;
        $refId       = $empresa ? $empresa->id : ('u' . $user->id);
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

        $accessToken = env('MP_ACCESS_TOKEN');
        if (!$accessToken) {
            return response()->json(['success' => false, 'message' => 'MP_ACCESS_TOKEN no configurado.'], 500);
        }

        MercadoPagoConfig::setAccessToken($accessToken);

        $appUrl     = env('APP_URL', '');
        $isLocalhost = str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1');

        $preferenceData = [
            'items' => [[
                'title'       => $titulo,
                'quantity'    => 1,
                'unit_price'  => (float) $precio,
                'currency_id' => 'ARS',
            ]],
            'back_urls' => [
                'success' => "{$frontendUrl}/pago/exito?plan={$request->plan}&ciclo={$ciclo}",
                'failure' => "{$frontendUrl}/pago/fallo?plan={$request->plan}",
                'pending' => "{$frontendUrl}/pago/exito?plan={$request->plan}&ciclo={$ciclo}&pending=1",
            ],
            'auto_return'         => 'approved',
            'external_reference'  => $refId . '|' . $request->plan . '|' . $ciclo,
            'statement_descriptor'=> 'Sistema Stock',
        ];

        // MP requiere HTTPS público para notification_url — no aplica en localhost
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

    public function webhook(Request $request): JsonResponse
    {
        if (!$this->firmaMercadoPagoValida($request)) {
            Log::warning('SubscripcionController::webhook firma x-signature inválida, se ignora');
            return response()->json(['ok' => true]);
        }

        $topic = $request->query('topic') ?? $request->input('type');

        if (!in_array($topic, ['payment', 'merchant_order'])) {
            return response()->json(['ok' => true]);
        }

        try {
            MercadoPagoConfig::setAccessToken(env('MP_ACCESS_TOKEN'));

            $paymentId = $request->input('data.id') ?? $request->query('id');
            if (!$paymentId) {
                return response()->json(['ok' => true]);
            }

            $payment = (new PaymentClient())->get((int) $paymentId);

            if ($payment->status !== 'approved') {
                return response()->json(['ok' => true]);
            }

            $parts = explode('|', $payment->external_reference);

            // Compra de un pack de facturación (ver FacturacionPackController) —
            // external_reference viene como "pack|{empresa_id}|{pack}", distinto
            // del formato "{empresa_id}|{plan}|{ciclo}" de un cambio de plan.
            if ($parts[0] === 'pack') {
                $empresaId = (int) ($parts[1] ?? 0);
                $pack      = $parts[2] ?? null;
                if ($empresaId && $pack) {
                    (new FacturacionPackController())->acreditarPack(
                        $empresaId, $pack, (string) $paymentId, $payment->transaction_amount ?? null
                    );
                }
                return response()->json(['ok' => true]);
            }

            $refId  = $parts[0];
            $plan   = $parts[1] ?? null;
            $ciclo  = $parts[2] ?? null;

            // MP puede reintentar la misma notificación (timeout, respuesta lenta, etc.)
            // y este endpoint es alcanzable sin firma — sin este chequeo, cada reintento
            // de un mismo pago ya aprobado generaba otra fila en pagos_suscripcion.
            if (PagoSuscripcion::where('payment_id', (string) $paymentId)->exists()) {
                return response()->json(['ok' => true]);
            }

            $empresa = Empresa::find((int) $refId);

            if ($empresa && $plan) {
                $planAnterior = $empresa->plan;

                $empresa->update([
                    'plan'          => $plan,
                    'trial_ends_at' => null,
                ]);
                $empresa->otorgarBonoFacturas($plan);

                PagoSuscripcion::create([
                    'empresa_id'    => $empresa->id,
                    'plan'          => $plan,
                    'plan_anterior' => $planAnterior,
                    'ciclo'         => $ciclo,
                    'monto'         => $payment->transaction_amount ?? null,
                    'payment_id'    => (string) $paymentId,
                ]);
            }
        } catch (\Exception) {
            // Siempre devolver 200 a MP para evitar reintentos
        }

        return response()->json(['ok' => true]);
    }
}
