<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPlanLimits;
use App\Models\MercadoPagoConexion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoPagoConexionController extends Controller
{
    use ChecksPlanLimits;

    /**
     * Devuelve la URL de autorización de Mercado Pago para que la empresa del
     * usuario autenticado conecte su propia cuenta (modelo Marketplace/Connect).
     */
    public function conectar(): JsonResponse
    {
        $empresa = auth('api')->user()->empresa;
        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada'], 400);
        }

        if ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'cobros',
            'Cobrar con Mercado Pago Point/QR está disponible desde el plan Pro. Actualizá tu plan para conectarlo.'
        )) {
            return $resp;
        }

        $state = Crypt::encryptString((string) $empresa->id);

        $url = 'https://auth.mercadopago.com/authorization?' . http_build_query([
            'client_id'     => env('MP_CLIENT_ID'),
            'response_type' => 'code',
            'platform_id'   => 'mp',
            'state'         => $state,
            'redirect_uri'  => env('MP_OAUTH_REDIRECT_URI'),
        ]);

        return response()->json(['success' => true, 'url' => $url]);
    }

    /**
     * Mercado Pago redirige acá después de que el usuario autoriza la app —
     * ruta pública, sin JWT, la identidad de la empresa viaja en `state`.
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

        try {
            $empresaId = (int) Crypt::decryptString($request->query('state', ''));
        } catch (\Exception $e) {
            return redirect("{$frontendUrl}/dashboard?mp=error");
        }

        $code = $request->query('code');
        if (!$empresaId || !$code) {
            return redirect("{$frontendUrl}/dashboard?mp=error");
        }

        try {
            $response = Http::asForm()->post('https://api.mercadopago.com/oauth/token', [
                'client_id'     => env('MP_CLIENT_ID'),
                'client_secret' => env('MP_CLIENT_SECRET'),
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => env('MP_OAUTH_REDIRECT_URI'),
            ]);

            if (!$response->successful()) {
                Log::warning('MercadoPagoConexionController::callback fallo al intercambiar code', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return redirect("{$frontendUrl}/dashboard?mp=error");
            }

            $data = $response->json();

            MercadoPagoConexion::updateOrCreate(
                ['empresa_id' => $empresaId],
                [
                    'mp_user_id'    => $data['user_id'] ?? '',
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'],
                    'expires_at'    => now()->addSeconds($data['expires_in'] ?? 15552000),
                ]
            );

            return redirect("{$frontendUrl}/dashboard?mp=conectado");
        } catch (\Exception $e) {
            Log::warning('MercadoPagoConexionController::callback excepción: ' . $e->getMessage());
            return redirect("{$frontendUrl}/dashboard?mp=error");
        }
    }

    public function desconectar(): JsonResponse
    {
        $empresa = auth('api')->user()->empresa;
        $empresa?->mercadoPagoConexion()->delete();

        return response()->json(['success' => true, 'message' => 'Cuenta de Mercado Pago desconectada']);
    }

    public function estado(): JsonResponse
    {
        $conexion = auth('api')->user()->empresa?->mercadoPagoConexion;

        return response()->json([
            'success'          => true,
            'conectado'        => (bool) $conexion,
            'point_device_id'  => $conexion?->point_device_id,
        ]);
    }

    /**
     * Lista los dispositivos Point vinculados a la cuenta de MP conectada.
     * NOTA: verificar este endpoint contra la documentación vigente de Mercado
     * Pago antes de salir a producción — la API de Point tiene versiones en
     * migración (integration-api "legacy" vs. la nueva Orders API).
     */
    public function dispositivos(\App\Services\MercadoPagoTokenService $tokens): JsonResponse
    {
        $empresa = auth('api')->user()->empresa;
        $token = $empresa ? $tokens->obtenerTokenValido($empresa) : null;

        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Conectá tu cuenta de Mercado Pago primero'], 400);
        }

        try {
            $response = Http::withToken($token)->get('https://api.mercadopago.com/point/integration-api/devices');

            if (!$response->successful()) {
                return response()->json(['success' => false, 'message' => 'No se pudieron obtener los dispositivos'], 502);
            }

            return response()->json(['success' => true, 'data' => $response->json('devices', [])]);
        } catch (\Exception $e) {
            Log::warning('MercadoPagoConexionController::dispositivos excepción: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al consultar dispositivos'], 500);
        }
    }

    public function guardarDispositivo(Request $request): JsonResponse
    {
        $request->validate(['point_device_id' => 'required|string']);

        $empresa = auth('api')->user()->empresa;
        $conexion = $empresa?->mercadoPagoConexion;

        if (!$conexion) {
            return response()->json(['success' => false, 'message' => 'Conectá tu cuenta de Mercado Pago primero'], 400);
        }

        $conexion->update(['point_device_id' => $request->point_device_id]);

        return response()->json(['success' => true, 'message' => 'Dispositivo guardado correctamente']);
    }
}
