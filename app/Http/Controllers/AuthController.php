<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\Sucursal;
use App\Models\User;
use App\Jobs\SendEmailJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only(['email', 'password']);

        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales inválidas',
            ], 401);
        }

        $user = auth('api')->user();

        if (config('features.two_factor_enabled') && $user->two_factor_enabled) {
            // No entregamos el JWT todavía — invalidamos el que acaba de emitir
            // attempt() y devolvemos un token de corta vida que solo sirve para
            // completar el segundo paso en /login/2fa.
            auth('api')->logout();

            $pendingToken = Str::random(64);
            Cache::put("2fa_pending:{$pendingToken}", $user->nro_usu, now()->addMinutes(5));

            return response()->json([
                'success'       => true,
                'requiere_2fa'  => true,
                'pending_token' => $pendingToken,
            ]);
        }

        $user->load(['rol.permisos', 'tipoUsuario', 'permisos', 'empresa', 'sucursal']);

        return $this->respondWithToken($token, $user);
    }

    // POST /login/2fa — segundo paso cuando el usuario tiene 2FA activo
    public function loginVerificar2fa(Request $request): JsonResponse
    {
        $request->validate([
            'pending_token' => 'required|string',
            'codigo'        => 'required|string',
        ]);

        $userId = Cache::get("2fa_pending:{$request->pending_token}");
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'El código expiró, iniciá sesión de nuevo',
            ], 401);
        }

        $user = User::find($userId);
        if (!$user || !$user->two_factor_secret) {
            return response()->json(['success' => false, 'message' => 'No se pudo verificar'], 401);
        }

        $google2fa = new Google2FA();
        $secret = Crypt::decryptString($user->two_factor_secret);

        if (!$google2fa->verifyKey($secret, $request->codigo)) {
            return response()->json(['success' => false, 'message' => 'Código incorrecto'], 422);
        }

        Cache::forget("2fa_pending:{$request->pending_token}");

        $token = JWTAuth::fromUser($user);
        $user->load(['rol.permisos', 'tipoUsuario', 'permisos', 'empresa', 'sucursal']);

        return $this->respondWithToken($token, $user);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );
            try {
                SendEmailJob::dispatch($user->email, $token);
            } catch (\Exception $e) {
                report($e);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Si el correo está registrado, recibirás las instrucciones en breve.',
        ]);
    }

    // POST /reset-password — segundo paso del flujo de "olvidé mi contraseña":
    // el link del mail trae token + email, acá se valida el token contra el
    // hash guardado en forgotPassword() y se pisa la contraseña.
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'token'    => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $registro = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$registro || !Hash::check($request->token, $registro->token)) {
            return response()->json(['success' => false, 'message' => 'El enlace no es válido o ya fue usado.'], 422);
        }

        if (now()->diffInMinutes($registro->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json(['success' => false, 'message' => 'El enlace expiró. Solicitá uno nuevo.'], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'El enlace no es válido o ya fue usado.'], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['success' => true, 'message' => 'Tu contraseña se actualizó correctamente.']);
    }

    public function me(): JsonResponse
    {
        $user = auth('api')->user()->load(['rol.permisos', 'tipoUsuario', 'permisos', 'empresa', 'sucursal']);

        return response()->json([
            'success'  => true,
            'user'     => $user,
            'permisos' => $user->obtenerTodosLosPermisos(),
        ]);
    }

    // POST /me/sucursal — cambia la sucursal activa del usuario. Requiere el
    // mismo permiso que el front usa para mostrar el switcher en el sidebar
    // (list-sucursales, ver DefaultLayout.jsx) — cualquier empleado con acceso
    // a varias sucursales puede cambiar, no solo is_super_admin.
    public function switchSucursal(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user->is_super_admin && !$user->chequearPermisos('list-sucursales')) {
            return response()->json(['success' => false, 'message' => 'No tenés permiso para cambiar de sucursal'], 403);
        }

        $validated = $request->validate([
            'id_sucursal' => 'required|exists:sucursales,id',
        ]);

        $sucursal = Sucursal::where('empresa_id', $user->empresa_id)->find($validated['id_sucursal']);
        if (!$sucursal) {
            return response()->json(['success' => false, 'message' => 'Sucursal no encontrada'], 404);
        }

        $user->id_sucursal = $sucursal->id;
        $user->save();
        $user->load(['rol.permisos', 'tipoUsuario', 'permisos', 'empresa', 'sucursal']);

        return response()->json([
            'success'  => true,
            'user'     => $user,
            'permisos' => $user->obtenerTodosLosPermisos(),
        ]);
    }

    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente',
        ]);
    }

    public function refresh(): JsonResponse
    {
        $token = JWTAuth::parseToken()->refresh();
        $user  = auth('api')->user()->load(['rol.permisos', 'tipoUsuario', 'permisos', 'empresa', 'sucursal']);

        return $this->respondWithToken($token, $user);
    }

    protected function respondWithToken(string $token, $user): JsonResponse
    {
        return response()->json([
            'success'      => true,
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => config('jwt.ttl') * 60,
            'user'         => $user,
        ]);
    }
}
