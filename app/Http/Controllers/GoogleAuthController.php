<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Tymon\JWTAuth\Facades\JWTAuth;

class GoogleAuthController extends Controller
{
    // GET /auth/google/redirect — inicia el flujo OAuth con Google.
    // El front redirige el navegador completo acá (href, no fetch), para que
    // Google pueda mostrar su consent screen y devolver el control al backend.
    public function redirect()
    {
        return Socialite::driver('google')
            ->stateless()
            ->redirect();
    }

    // GET /auth/google/callback — Google redirige acá después de la autorización.
    // Verifica el usuario de Google, lo busca o lo vincula por email/google_id,
    // emite un JWT, y redirige al front con el token en la URL.
    public function callback(Request $request)
    {
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            return redirect($frontendUrl . '/signin?error=google_auth_failed');
        }

        if (!$googleUser || !$googleUser->getEmail()) {
            return redirect($frontendUrl . '/signin?error=google_no_email');
        }

        $email = $googleUser->getEmail();
        $googleId = $googleUser->getId();

        // Buscar usuario por google_id (ya vinculado previamente)
        $user = User::where('google_id', $googleId)->first();

        // Si no, buscar por email (primer login con Google o cuenta existente)
        if (!$user) {
            $user = User::where('email', $email)->first();

            if ($user) {
                // Vincular la cuenta de Google al usuario existente
                $user->google_id = $googleId;
                $user->save();
            }
        }

        // Si no hay usuario registrado con ese email, no podemos crear una
        // cuenta nueva acá: este sistema es multi-tenant y cada cuenta nueva
        // requiere crear una empresa, sucursal, permisos, etc. (ver register()).
        // Redirigimos al registro con los datos pre-cargados de Google.
        if (!$user) {
            $name = $googleUser->getName() ?: '';
            return redirect(
                $frontendUrl . '/signin?view=register'
                . '&google_name=' . urlencode($name)
                . '&google_email=' . urlencode($email)
            );
        }

        // Si el usuario tiene 2FA activo no podemos devolver el JWT directo
        // (mismo criterio que AuthController::login). En este caso generamos un
        // pending_token y redirigimos al front con ese dato para que muestre el
        // paso de 2FA.
        if (config('features.two_factor_enabled') && $user->two_factor_enabled) {
            $pendingToken = Str::random(64);
            Cache::put("2fa_pending:{$pendingToken}", $user->nro_usu, now()->addMinutes(5));

            return redirect(
                $frontendUrl . '/signin?pending_2fa=' . urlencode($pendingToken)
            );
        }

        $user->load(['rol.permisos', 'tipoUsuario', 'permisos', 'empresa', 'sucursal']);

        $token = JWTAuth::fromUser($user);

        return redirect(
            $frontendUrl . '/oauth-callback?token=' . urlencode($token)
            . '&expires_in=' . (config('jwt.ttl') * 60)
        );
    }
}
