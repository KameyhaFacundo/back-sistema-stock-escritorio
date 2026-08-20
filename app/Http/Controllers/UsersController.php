<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Jobs\SendEmailChangeVerificationJob;
use App\Jobs\SendEmailVerificationJob;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Tymon\JWTAuth\Facades\JWTAuth;

class UsersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['rol', 'tipoUsuario', 'sucursal:id,nombre'])
            ->where('empresa_id', auth()->user()->empresa_id)
            // La cuenta inicial queda como acceso de respaldo, pero no se
            // muestra en la gestión normal de usuarios del negocio.
            ->where('email', '!=', 'admin@gmail.com');

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('des_usu', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('id_rol') && $request->id_rol) {
            $query->where('id_rol', $request->id_rol);
        }

        if ($request->has('id_tipo_usuario') && $request->id_tipo_usuario) {
            $query->where('id_tipo_usuario', $request->id_tipo_usuario);
        }

        if ($request->has('con_eliminados') && $request->con_eliminados) {
            $query->withTrashed();
        }

        $users = $query->orderBy('des_usu')->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    public function show($id): JsonResponse
    {
        $user = User::with(['rol.permisos', 'tipoUsuario', 'permisos', 'sucursal:id,nombre'])
            ->where('empresa_id', auth()->user()->empresa_id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            // Nota: sin chequeo de límite de plan a propósito (ver el mismo
            // comentario en ProductosController::store()) — este build es de
            // un solo comercio, sin planes pagos.

            // Token de verificación de email — mismo mecanismo que actualizarPerfil/
            // confirmarEmail, acá sin email_pendiente porque no hay cambio, solo
            // se confirma el email con el que se lo dio de alta.
            $tokenVerificacion = Str::random(64);

            $user = User::create([
                'des_usu' => $request->des_usu,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'id_tipo_usuario' => $request->id_tipo_usuario,
                'id_rol' => $request->id_rol,
                'empresa_id' => auth()->user()->empresa_id,
                // Sin sucursal explícita, hereda la del usuario que lo está creando —
                // sin esto el usuario nuevo queda bloqueado para vender/comprar/abrir caja.
                'id_sucursal' => $request->id_sucursal ?? auth()->user()->id_sucursal,
                'email_token' => Hash::make($tokenVerificacion),
                'email_token_created_at' => now(),
            ]);

            if ($request->has('permisos') && is_array($request->permisos)) {
                $user->permisos()->sync($request->permisos);
            } elseif ($user->id_rol) {
                // Sin permisos explícitos: el rol se usa como plantilla inicial
                // (una sola vez, al crear). De ahí en más el rol es solo una etiqueta.
                $idsRol = $user->rol?->permisos->pluck('id') ?? collect();
                $user->permisos()->sync($idsRol);
            }

            DB::commit();

            try {
                SendEmailVerificationJob::dispatch($user->email, $tokenVerificacion, $user->nro_usu);
            } catch (\Exception $e) {
                report($e);
            }

            $user->load(['rol.permisos', 'tipoUsuario', 'permisos', 'sucursal:id,nombre']);

            return response()->json([
                'success' => true,
                'message' => 'Usuario creado correctamente',
                'data' => $user,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el usuario',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateUserRequest $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            // Scopeado por empresa igual que show()/destroy() — sin esto cualquier
            // usuario con permiso update-usuarios podía editar (nombre, email,
            // CONTRASEÑA, rol, permisos) al usuario de OTRA empresa con solo
            // adivinar su nro_usu (User no tiene HasTenant, no hay scope automático).
            $user = User::where('empresa_id', auth()->user()->empresa_id)->findOrFail($id);
            $rolAnterior = $user->id_rol;

            $data = $request->only(['des_usu', 'email', 'id_tipo_usuario', 'id_rol', 'id_sucursal']);

            if ($request->has('password') && $request->password) {
                $data['password'] = Hash::make($request->password);
            }

            $user->update($data);

            if ($request->has('permisos')) {
                $user->permisos()->sync($request->permisos ?? []);
            } elseif ($request->has('id_rol') && $request->id_rol != $rolAnterior) {
                // Cambiar el rol es un "aplicar preset": copia los permisos de ese rol
                // como directos (reemplazando los actuales). El rol en sí no otorga nada.
                $idsRol = $user->rol?->permisos->pluck('id') ?? collect();
                $user->permisos()->sync($idsRol);
            }

            DB::commit();

            $user->load(['rol.permisos', 'tipoUsuario', 'permisos', 'sucursal:id,nombre']);

            return response()->json([
                'success' => true,
                'message' => 'Usuario actualizado correctamente',
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el usuario',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $user = User::where('empresa_id', auth()->user()->empresa_id)->findOrFail($id);
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Usuario eliminado correctamente',
        ]);
    }

    public function restore($id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        $user->restore();

        return response()->json([
            'success' => true,
            'message' => 'Usuario restaurado correctamente',
            'data' => $user,
        ]);
    }

    // Self-service — el propio usuario logueado edita su nombre/email (a
    // diferencia de update(), que es un admin editando a otro usuario y por
    // eso pide el permiso update-usuarios).
    //
    // El email NO se aplica directo: mismo criterio de seguridad que "olvidé
    // mi contraseña" (AuthController::forgotPassword/resetPassword) — queda
    // pendiente hasta que se confirma desde el link mandado a la dirección
    // nueva. Así un typo no te deja sin acceso, y nadie puede secuestrar la
    // cuenta con una sesión robada sin que el dueño real del mail nuevo lo
    // confirme.
    public function actualizarPerfil(Request $request): JsonResponse
    {
        $user = auth()->user();

        $request->validate([
            'des_usu' => 'required|string|max:255',
            'email'   => ['required', 'email', Rule::unique('users', 'email')->ignore($user->nro_usu, 'nro_usu')],
        ]);

        $user->des_usu = $request->des_usu;

        $emailPendiente = false;

        if ($request->email === $user->email) {
            // Volvió a poner el email actual/confirmado — cancela cualquier
            // cambio pendiente sin necesitar un botón aparte para eso.
            $user->email_pendiente = null;
            $user->email_token = null;
            $user->email_token_created_at = null;
        } elseif ($request->email !== $user->email_pendiente) {
            $token = Str::random(64);
            $user->email_pendiente = $request->email;
            $user->email_token = Hash::make($token);
            $user->email_token_created_at = now();
            $emailPendiente = true;
            try {
                SendEmailChangeVerificationJob::dispatch($request->email, $token, $user->nro_usu);
            } catch (\Exception $e) {
                report($e);
            }
        } else {
            // Ya estaba pendiente exactamente este mismo email — no reenvía
            // el mail de nuevo si guardan el formulario dos veces.
            $emailPendiente = true;
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => $emailPendiente
                ? 'Datos guardados. Te mandamos un correo a tu nueva dirección para confirmar el cambio de email.'
                : 'Perfil actualizado correctamente',
            'email_pendiente' => $emailPendiente,
            'data' => $user,
        ]);
    }

    // Segundo paso del cambio de email — el link del mail trae token + id,
    // acá se valida contra el hash guardado en actualizarPerfil() y recién
    // ahí se aplica el email nuevo. Ruta pública (sin JWT): el token es la
    // prueba de identidad, mismo criterio que reset-password.
    public function confirmarEmail(Request $request): JsonResponse
    {
        $request->validate([
            'id'    => 'required|integer',
            'token' => 'required|string',
        ]);

        $user = User::find($request->id);

        if (!$user || !$user->email_token || !Hash::check($request->token, $user->email_token)) {
            return response()->json(['success' => false, 'message' => 'El enlace no es válido o ya fue usado.'], 422);
        }

        if (now()->diffInMinutes($user->email_token_created_at) > 60) {
            $user->update(['email_pendiente' => null, 'email_token' => null, 'email_token_created_at' => null]);
            return response()->json(['success' => false, 'message' => 'El enlace expiró. Pedí el cambio de email de nuevo desde tu perfil.'], 422);
        }

        // Sin email_pendiente: no es un cambio de email, es la verificación
        // inicial de la cuenta recién creada — solo marca email_verified_at.
        if (!$user->email_pendiente) {
            $user->update(['email_token' => null, 'email_token_created_at' => null, 'email_verified_at' => now()]);
            return response()->json(['success' => true, 'message' => 'Tu cuenta quedó verificada.']);
        }

        // Re-valida unicidad por si alguien más tomó ese email en la ventana de espera.
        $yaExiste = User::where('email', $user->email_pendiente)->where('nro_usu', '!=', $user->nro_usu)->exists();
        if ($yaExiste) {
            $user->update(['email_pendiente' => null, 'email_token' => null, 'email_token_created_at' => null]);
            return response()->json(['success' => false, 'message' => 'Ese correo ya está en uso por otra cuenta.'], 422);
        }

        $user->update([
            'email'                  => $user->email_pendiente,
            'email_pendiente'        => null,
            'email_token'            => null,
            'email_token_created_at' => null,
            'email_verified_at'      => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Tu correo se actualizó correctamente.']);
    }

    public function cambiarPassword(Request $request): JsonResponse
    {
        $request->validate([
            'password_actual' => 'required|string',
            'password_nuevo' => 'required|string|min:6',
        ]);

        $user = auth()->user();

        if (!Hash::check($request->password_actual, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña actual es incorrecta',
            ], 400);
        }

        $user->update([
            'password' => Hash::make($request->password_nuevo),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada correctamente',
        ]);
    }

    public function configurarUsuarioInicial(Request $request): JsonResponse
    {
        $usuarioInicial = auth()->user();

        if ($usuarioInicial->configuracion_inicial_completada) {
            return response()->json(['success' => false, 'message' => 'La configuración inicial ya fue completada.'], 422);
        }

        $validated = $request->validate([
            'des_usu'        => 'required|string|max:255',
            'email'          => ['required', 'email', Rule::unique('users', 'email')],
            'password'       => 'required|string|min:6|confirmed',
            'nombre_negocio' => 'required|string|max:255',
        ]);

        $empresa = $usuarioInicial->empresa;
        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada.'], 400);
        }

        $rolAdmin = Rol::where('codigo', 'admin')->first();
        if (!$rolAdmin) {
            return response()->json(['success' => false, 'message' => 'No se encontró el rol Administrador.'], 500);
        }

        $nuevoUsuario = User::create([
            'des_usu'  => $validated['des_usu'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'id_tipo_usuario' => $usuarioInicial->id_tipo_usuario,
            'id_rol' => $rolAdmin->id,
            'empresa_id' => $usuarioInicial->empresa_id,
            'id_sucursal' => $usuarioInicial->id_sucursal,
            'configuracion_inicial_completada' => true,
        ]);

        if ($rolAdmin) {
            $nuevoUsuario->permisos()->sync($rolAdmin->permisos->pluck('id'));
        }

        $usuarioInicial->configuracion_inicial_completada = true;
        $usuarioInicial->save();
        $empresa->update(['nombre' => $validated['nombre_negocio']]);

        $nuevoUsuario->load(['rol.permisos', 'tipoUsuario', 'permisos', 'empresa', 'sucursal']);
        $token = JWTAuth::fromUser($nuevoUsuario);

        return response()->json([
            'success' => true,
            'message' => 'Usuario propio creado correctamente',
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
            'user' => $nuevoUsuario,
            'permisos' => $nuevoUsuario->obtenerTodosLosPermisos(),
        ], 201);
    }

    /**
     * PIN corto para autorizar descuentos desde el POS (ver VentasController::
     * autorizarDescuento) sin tipear la contraseña completa en la pantalla del
     * cajero. Confirmar con la contraseña de login antes de cambiarlo — mismo
     * criterio que cambiarPassword(), evita que alguien con la sesión abierta
     * y desatendida se ponga un PIN propio sin saber la contraseña real.
     */
    public function cambiarPin(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
            'pin' => 'required|string|min:4|max:6|regex:/^[0-9]+$/',
        ]);

        $user = auth()->user();

        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña es incorrecta',
            ], 400);
        }

        $user->update([
            'pin' => Hash::make($request->pin),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'PIN configurado correctamente',
        ]);
    }
}
