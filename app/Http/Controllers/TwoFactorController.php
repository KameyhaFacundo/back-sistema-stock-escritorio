<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function estado(): JsonResponse
    {
        $user = auth('api')->user();

        return response()->json([
            'success'    => true,
            'activo'     => (bool) $user->two_factor_enabled,
            'disponible' => (bool) config('features.two_factor_enabled'),
        ]);
    }

    /**
     * POST /2fa/activar — genera un secret nuevo (todavía no queda activo hasta
     * que el usuario confirme con un código real de su app en confirmar()).
     */
    public function activar(): JsonResponse
    {
        if (!config('features.two_factor_enabled')) {
            return response()->json(['success' => false, 'message' => 'La verificación en dos pasos no está disponible'], 403);
        }

        $user = auth('api')->user();
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user->two_factor_secret = Crypt::encryptString($secret);
        $user->two_factor_enabled = false;
        $user->two_factor_confirmed_at = null;
        $user->save();

        $otpauthUrl = $google2fa->getQRCodeUrl(
            config('app.name', 'Kamex Solutions'),
            $user->email,
            $secret
        );

        return response()->json(['success' => true, 'otpauth_url' => $otpauthUrl]);
    }

    // POST /2fa/confirmar — valida el primer código y recién ahí queda activo
    public function confirmar(Request $request): JsonResponse
    {
        if (!config('features.two_factor_enabled')) {
            return response()->json(['success' => false, 'message' => 'La verificación en dos pasos no está disponible'], 403);
        }

        $request->validate(['codigo' => 'required|string']);
        $user = auth('api')->user();

        if (!$user->two_factor_secret) {
            return response()->json(['success' => false, 'message' => 'Primero tenés que iniciar la activación'], 400);
        }

        $google2fa = new Google2FA();
        $secret = Crypt::decryptString($user->two_factor_secret);

        if (!$google2fa->verifyKey($secret, $request->codigo)) {
            return response()->json(['success' => false, 'message' => 'Código incorrecto'], 422);
        }

        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->save();

        return response()->json(['success' => true, 'message' => 'Verificación en dos pasos activada']);
    }

    // POST /2fa/desactivar — pide la contraseña para no dejar que cualquiera con
    // la sesión abierta apague la protección.
    public function desactivar(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $user = auth('api')->user();

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['success' => false, 'message' => 'Contraseña incorrecta'], 422);
        }

        $user->two_factor_secret = null;
        $user->two_factor_enabled = false;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return response()->json(['success' => true, 'message' => 'Verificación en dos pasos desactivada']);
    }
}
