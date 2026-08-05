<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmailVerificationJob;
use App\Models\Empresa;
use App\Models\PagoFacturacionPack;
use App\Models\PagoSuscripcion;
use App\Models\Producto;
use App\Models\SuperAdminLog;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Tymon\JWTAuth\Facades\JWTAuth;

class SuperAdminController extends Controller
{
    private function checkSuperAdmin(): ?JsonResponse
    {
        if (!auth()->user()->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado'], 403);
        }
        return null;
    }

    // Registro de auditoría — se llama después de cada acción que modifica una
    // empresa o sus usuarios, para que quede un historial visible en el panel
    // (antes estas acciones se ejecutaban sin dejar ningún rastro consultable).
    private function registrarActividad(string $accion, ?Empresa $empresa, ?string $detalle = null): void
    {
        $superAdmin = auth()->user();
        SuperAdminLog::create([
            'super_admin_id'    => $superAdmin?->nro_usu,
            'super_admin_email' => $superAdmin?->email,
            'empresa_id'        => $empresa?->id,
            'empresa_nombre'    => $empresa?->nombre,
            'accion'            => $accion,
            'detalle'           => $detalle,
        ]);
    }

    private function mapEmpresa($e): array
    {
        return [
            'id'            => $e->id,
            'nombre'        => $e->nombre,
            'tipo'          => $e->tipo,
            'plan'          => $e->plan,
            'trial_ends_at' => $e->trial_ends_at,
            'suspendida'    => $e->suspendida,
            'arca'          => $e->arca,
            'pos'           => $e->pos,
            'created_at'    => $e->created_at,
            'usuarios_count'    => $e->usuarios->count(),
            'sucursales_count'  => $e->sucursales->count(),
            'owner_email'   => $e->usuarios->first()?->email,
        ];
    }

    // GET /super-admin/empresas
    public function empresas(): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        // Sucursal usa HasTenant (auto-scope por la empresa del usuario logueado) —
        // acá el super admin necesita ver las de CADA empresa, no solo la propia,
        // así que hay que pedir el eager load sin ese global scope.
        $empresas = Empresa::with(['usuarios', 'sucursales' => fn($q) => $q->withoutGlobalScope('tenant')])
            ->orderBy('created_at', 'desc')->get()
            ->map(fn($e) => $this->mapEmpresa($e));

        return response()->json(['success' => true, 'data' => $empresas]);
    }

    // GET /super-admin/empresas/{id}
    public function empresa($id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $empresa = Empresa::with(['usuarios.rol', 'sucursales' => fn($q) => $q->withoutGlobalScope('tenant')
                ->withCount(['stocks as productos_con_stock' => fn($sq) => $sq->withoutGlobalScope('tenant')->where('stock', '>', 0)])])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id'            => $empresa->id,
                'nombre'        => $empresa->nombre,
                'tipo'          => $empresa->tipo,
                'plan'          => $empresa->plan,
                'trial_ends_at' => $empresa->trial_ends_at,
                'suspendida'    => $empresa->suspendida,
                'owner_email'   => $empresa->usuarios->first()?->email,
                'arca'          => $empresa->arca,
                'pos'           => $empresa->pos,
                'created_at'    => $empresa->created_at,
                'usuarios'      => $empresa->usuarios->map(fn($u) => [
                    'id'                => $u->nro_usu,
                    'nombre'            => $u->des_usu,
                    'email'             => $u->email,
                    'rol'               => $u->rol?->nombre,
                    'is_admin'          => $u->esAdministrador(),
                    'created_at'        => $u->created_at,
                    'email_verified_at' => $u->email_verified_at,
                ]),
                'sucursales'    => $empresa->sucursales->map(fn($s) => [
                    'id'           => $s->id,
                    'nombre'       => $s->nombre,
                    'direccion'    => $s->direccion,
                    'activo'       => $s->activo,
                    'es_principal' => $s->es_principal,
                    'productos_con_stock' => $s->productos_con_stock,
                    'created_at'   => $s->created_at,
                ]),
                'actividad'     => $this->actividadEmpresa($empresa),
            ],
        ]);
    }

    // Actividad/salud de una empresa: última venta, ventas del mes y uso vs
    // límites del plan — pensado para detectar clientes inactivos o cerca de
    // su tope antes de que se den de baja. Venta/Producto usan HasTenant
    // (auto-scope por la empresa del usuario logueado, que acá es el super
    // admin), así que hay que bypasear ese scope y filtrar por empresa_id a mano.
    private function actividadEmpresa(Empresa $empresa): array
    {
        $ventasQuery = fn() => Venta::withoutGlobalScope('tenant')
            ->where('empresa_id', $empresa->id)
            ->where('estado', '!=', 'cancelada');

        $ultimaVenta = $ventasQuery()->orderByDesc('fecha')->orderByDesc('hora')->first();

        $ventasMes = $ventasQuery()
            ->whereYear('fecha', now()->year)
            ->whereMonth('fecha', now()->month)
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(monto_total), 0) as monto')
            ->first();

        $productosCount = Producto::withoutGlobalScope('tenant')->where('empresa_id', $empresa->id)->count();
        $usuariosCount  = $empresa->usuarios->count();

        $limites = config('planes.limites')[$empresa->plan] ?? config('planes.limites')['free'];

        return [
            'ultima_venta_fecha' => $ultimaVenta?->fecha,
            'ventas_mes' => [
                'cantidad' => (int) ($ventasMes->cantidad ?? 0),
                'monto'    => (float) ($ventasMes->monto ?? 0),
            ],
            'limites' => [
                'productos' => ['usados' => $productosCount, 'max' => $limites['productos'] ?? null],
                'usuarios'  => ['usados' => $usuariosCount, 'max' => $limites['usuarios'] ?? null],
            ],
            'facturas_disponibles' => $empresa->facturas_disponibles,
        ];
    }

    // PUT /super-admin/empresas/{id}
    public function updateEmpresa(Request $request, $id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $empresa      = Empresa::findOrFail($id);
        $planAnterior = $empresa->plan;
        $empresa->update($request->only(['nombre', 'plan', 'trial_ends_at', 'arca', 'pos']));

        if ($request->filled('plan')) {
            $empresa->otorgarBonoFacturas($request->plan);
        }

        if ($request->filled('plan') && $request->plan !== $planAnterior) {
            $this->registrarActividad('editar_plan', $empresa, "Plan: {$planAnterior} → {$empresa->plan}");
        }

        return response()->json(['success' => true, 'message' => 'Empresa actualizada']);
    }

    // POST /super-admin/empresas/{id}/registrar-pago
    public function registrarPago(Request $request, $id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $request->validate([
            'plan'   => 'required|in:esencial,pro,ia',
            'ciclo'  => 'required|in:mensual,anual',
            'monto'  => 'required|numeric|min:0',
            'metodo' => 'nullable|string|max:50',
        ]);

        $empresa      = Empresa::findOrFail($id);
        $planAnterior = $empresa->plan;

        $empresa->update([
            'plan'          => $request->plan,
            'trial_ends_at' => null,
        ]);
        $empresa->otorgarBonoFacturas($request->plan);

        PagoSuscripcion::create([
            'empresa_id'    => $empresa->id,
            'plan'          => $request->plan,
            'plan_anterior' => $planAnterior,
            'ciclo'         => $request->ciclo,
            'monto'         => $request->monto,
            'payment_id'    => 'manual:' . ($request->metodo ?? 'efectivo'),
        ]);

        $this->registrarActividad('registrar_pago', $empresa, "Plan {$request->plan} ({$request->ciclo}) · $" . number_format((float) $request->monto, 2));

        return response()->json([
            'success' => true,
            'message' => 'Pago registrado',
            'plan'    => $request->plan,
        ]);
    }

    // POST /super-admin/empresas/{id}/facturas — acredita facturas manualmente
    // (pago por fuera de Mercado Pago, o cortesía) sin pasar por un pack fijo
    // de config('planes.packs_facturacion'); mismo criterio de auditoría que
    // registrarPago (fila en pagos_facturacion_packs + registrarActividad).
    public function acreditarFacturas(Request $request, $id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $request->validate([
            'cantidad' => 'required|integer|min:1',
            'monto'    => 'nullable|numeric|min:0',
            'metodo'   => 'nullable|string|max:50',
        ]);

        $empresa = Empresa::findOrFail($id);
        $empresa->increment('facturas_disponibles', $request->cantidad);

        PagoFacturacionPack::create([
            'empresa_id' => $empresa->id,
            'pack'       => 'manual',
            'cantidad'   => $request->cantidad,
            'monto'      => $request->monto,
            'payment_id' => 'manual:' . ($request->metodo ?? 'ajuste') . ':' . uniqid(),
        ]);

        $this->registrarActividad('acreditar_facturas', $empresa, "+{$request->cantidad} facturas" . ($request->monto ? ' · $' . number_format((float) $request->monto, 2) : ''));

        return response()->json([
            'success'              => true,
            'message'              => 'Facturas acreditadas',
            'facturas_disponibles' => $empresa->facturas_disponibles,
        ]);
    }

    // POST /super-admin/empresas/{id}/usuarios
    public function crearUsuario(Request $request, $id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $request->validate([
            'des_usu'  => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        $rolAdmin = \App\Models\Rol::where('codigo', 'admin')->first();
        $esAdmin  = $request->boolean('is_admin');

        // Todo usuario necesita una sucursal para operar — se lo asigna a la principal
        // de la empresa por defecto (puede reasignarse después desde Usuarios).
        // El super admin actúa sin empresa propia, así que hay que salir del scope
        // de tenant explícitamente (ver HasTenant) en vez de depender de que quede
        // sin filtrar.
        $sucursalPrincipal = \App\Models\Sucursal::withoutGlobalScope('tenant')
            ->where('empresa_id', $id)->where('es_principal', true)->first();

        // Token de verificación de email — mismo mecanismo que actualizarPerfil/
        // confirmarEmail de UsersController, acá sin email_pendiente porque no
        // hay cambio, solo se confirma el email con el que se lo dio de alta.
        $tokenVerificacion = Str::random(64);

        $user = User::create([
            'des_usu'                => $request->des_usu,
            'email'                  => $request->email,
            'password'               => Hash::make($request->password),
            'empresa_id'             => $id,
            'id_rol'                 => $esAdmin ? $rolAdmin?->id : null,
            'id_sucursal'            => $sucursalPrincipal?->id,
            'email_token'            => Hash::make($tokenVerificacion),
            'email_token_created_at' => now(),
        ]);

        if ($esAdmin && $rolAdmin) {
            $user->permisos()->sync($rolAdmin->permisos->pluck('id'));
        }

        $this->registrarActividad('crear_usuario', Empresa::find($id), "{$user->des_usu} ({$user->email})" . ($esAdmin ? ' · admin' : ''));

        try {
            SendEmailVerificationJob::dispatch($user->email, $tokenVerificacion, $user->nro_usu);
        } catch (\Exception $e) {
            report($e);
        }

        return response()->json(['success' => true, 'message' => 'Usuario creado', 'data' => [
            'id'                => $user->nro_usu,
            'nombre'            => $user->des_usu,
            'email'             => $user->email,
            'is_admin'          => $user->esAdministrador(),
            'created_at'        => $user->created_at,
            'email_verified_at' => $user->email_verified_at,
        ]], 201);
    }

    // DELETE /super-admin/usuarios/{id}
    public function eliminarUsuario($id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $user = User::findOrFail($id);
        if ($user->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'No se puede eliminar un super admin'], 403);
        }
        $this->registrarActividad('eliminar_usuario', Empresa::find($user->empresa_id), "{$user->des_usu} ({$user->email})");
        $user->delete();

        return response()->json(['success' => true, 'message' => 'Usuario eliminado']);
    }

    // PUT /super-admin/usuarios/{id}/toggle-admin
    public function toggleAdmin($id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $user = User::findOrFail($id);
        $rolAdmin   = \App\Models\Rol::where('codigo', 'admin')->first();
        $rolUsuario = \App\Models\Rol::where('codigo', 'usuario')->first();
        $eraAdmin   = $user->esAdministrador();

        // Al desactivar no lo dejamos sin rol (perdería hasta el acceso básico):
        // vuelve al rol "Usuario" en vez de quedar en null. El rol es solo una etiqueta,
        // así que también hay que copiar sus permisos como directos para que el cambio
        // tenga efecto real.
        $nuevoRol = $eraAdmin ? $rolUsuario : $rolAdmin;
        $user->update(['id_rol' => $nuevoRol?->id]);
        $user->permisos()->sync($nuevoRol?->permisos->pluck('id') ?? collect());

        $this->registrarActividad($eraAdmin ? 'quitar_admin' : 'otorgar_admin', Empresa::find($user->empresa_id), "{$user->des_usu} ({$user->email})");

        return response()->json(['success' => true, 'is_admin' => !$eraAdmin]);
    }

    // POST /super-admin/empresas/{id}/extender-trial
    public function extenderTrial(Request $request, $id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $request->validate(['dias' => 'required|integer|min:1|max:365']);

        $empresa = Empresa::findOrFail($id);
        $base    = ($empresa->trial_ends_at && $empresa->trial_ends_at->isFuture())
                    ? $empresa->trial_ends_at
                    : now();

        $empresa->update(['trial_ends_at' => $base->addDays($request->dias)]);
        $this->registrarActividad('extender_prueba', $empresa, "+{$request->dias} días");

        return response()->json([
            'success'       => true,
            'trial_ends_at' => $empresa->fresh()->trial_ends_at,
        ]);
    }

    // PUT /super-admin/empresas/{id}/toggle-suspendida
    public function toggleSuspendida($id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $empresa = Empresa::findOrFail($id);
        $empresa->update(['suspendida' => !$empresa->suspendida]);
        $this->registrarActividad($empresa->suspendida ? 'suspender' : 'reactivar', $empresa);

        return response()->json([
            'success'    => true,
            'suspendida' => $empresa->fresh()->suspendida,
        ]);
    }

    // GET /super-admin/alertas-trial
    public function alertasTrial(): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $empresas = Empresa::with('usuarios')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now())
            ->where('trial_ends_at', '<=', now()->addDays(3))
            ->where('plan', 'free')
            ->get()
            ->map(fn($e) => [
                'id'            => $e->id,
                'nombre'        => $e->nombre,
                'trial_ends_at' => $e->trial_ends_at,
                'owner_email'   => $e->usuarios->first()?->email,
                'dias_restantes'=> (int) ceil(now()->diffInHours($e->trial_ends_at) / 24),
            ]);

        return response()->json(['success' => true, 'data' => $empresas]);
    }

    // GET /super-admin/historial-pagos — combina pagos de plan y de packs de
    // facturación en un solo historial ordenado por fecha (antes solo veía
    // pagos_suscripcion, dejando invisible la plata real que entra por packs).
    public function historialPagos(Request $request): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $limit = $request->limit ?? 50;

        $pagosPlanes = PagoSuscripcion::with('empresa')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn($p) => [
                'id'             => 'plan-' . $p->id,
                'tipo'           => 'plan',
                'empresa_id'     => $p->empresa_id,
                'empresa_nombre' => $p->empresa?->nombre,
                'plan'           => $p->plan,
                'plan_anterior'  => $p->plan_anterior,
                'ciclo'          => $p->ciclo,
                'monto'          => $p->monto,
                'payment_id'     => $p->payment_id,
                'fecha'          => $p->created_at,
            ]);

        $pagosPacks = PagoFacturacionPack::with('empresa')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn($p) => [
                'id'             => 'pack-' . $p->id,
                'tipo'           => 'pack',
                'empresa_id'     => $p->empresa_id,
                'empresa_nombre' => $p->empresa?->nombre,
                'pack_label'     => "Pack {$p->cantidad} facturas",
                'monto'          => $p->monto,
                'payment_id'     => $p->payment_id,
                'fecha'          => $p->created_at,
            ]);

        $pagos = $pagosPlanes->concat($pagosPacks)
            ->sortByDesc('fecha')
            ->values()
            ->take($limit);

        return response()->json(['success' => true, 'data' => $pagos]);
    }

    // PUT /super-admin/usuarios/{id}/reset-password
    public function resetearPassword(Request $request, $id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $request->validate(['password' => 'required|string|min:6']);

        $user = User::findOrFail($id);
        $user->update(['password' => Hash::make($request->password)]);
        $this->registrarActividad('resetear_password', Empresa::find($user->empresa_id), "{$user->des_usu} ({$user->email})");

        return response()->json(['success' => true, 'message' => 'Contraseña actualizada']);
    }

    // PUT /super-admin/usuarios/{id} — a diferencia de UsersController::actualizarPerfil
    // (self-service, el propio usuario cambiando su email vía link de confirmación),
    // acá el super admin edita a OTRO usuario de soporte/administración, así que el
    // email se aplica directo, sin verificación.
    public function actualizarUsuario(Request $request, $id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $user = User::findOrFail($id);

        $request->validate([
            'des_usu' => 'required|string|max:255',
            'email'   => ['required', 'email', Rule::unique('users', 'email')->ignore($user->nro_usu, 'nro_usu')],
        ]);

        $emailAnterior = $user->email;
        $user->update(['des_usu' => $request->des_usu, 'email' => $request->email]);

        if ($request->email !== $emailAnterior) {
            $this->registrarActividad('editar_usuario', Empresa::find($user->empresa_id), "{$emailAnterior} → {$user->email}");
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado',
            'data'    => ['id' => $user->nro_usu, 'nombre' => $user->des_usu, 'email' => $user->email],
        ]);
    }

    // GET /super-admin/ingresos
    public function ingresos(): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        // MRR estimado: por cada empresa activa con plan pago se toma su pago
        // más reciente y se normaliza a mensual (anual / 12). Si una empresa
        // tiene el plan cambiado a mano (sin pago registrado, ej. cortesía)
        // no suma al MRR — no hubo cobro real.
        $planesPagos = array_keys(array_filter(config('planes.limites'), fn($k) => $k !== 'free', ARRAY_FILTER_USE_KEY));

        $empresasPagas = Empresa::where('suspendida', false)
            ->whereIn('plan', $planesPagos)
            ->with(['pagos' => fn($q) => $q->orderByDesc('created_at')->limit(1)])
            ->get();

        $mrrPorPlan      = array_fill_keys($planesPagos, 0.0);
        $empresasPorPlan = array_fill_keys($planesPagos, 0);

        foreach ($empresasPagas as $empresa) {
            $ultimoPago = $empresa->pagos->first();
            if (!$ultimoPago) continue;
            $mensual = $ultimoPago->ciclo === 'anual' ? $ultimoPago->monto / 12 : (float) $ultimoPago->monto;
            $mrrPorPlan[$empresa->plan]      += $mensual;
            $empresasPorPlan[$empresa->plan] += 1;
        }

        // Tendencia: ingresos efectivamente cobrados (no MRR) por mes, últimos 6
        // meses — suma pagos de plan Y de packs de facturación (packs son pago
        // único, no aportan a MRR, pero sí son plata real cobrada ese mes).
        $desde = now()->subMonths(5)->startOfMonth();
        $pagosPorMes = PagoSuscripcion::where('created_at', '>=', $desde)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as mes, SUM(monto) as monto, COUNT(*) as cantidad")
            ->groupBy('mes')->get()->keyBy('mes');

        $packsPorMes = PagoFacturacionPack::where('created_at', '>=', $desde)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as mes, SUM(monto) as monto, COUNT(*) as cantidad")
            ->groupBy('mes')->get()->keyBy('mes');

        $tendencia = [];
        for ($i = 5; $i >= 0; $i--) {
            $clave        = now()->subMonths($i)->format('Y-m');
            $registro     = $pagosPorMes->get($clave);
            $registroPack = $packsPorMes->get($clave);
            $tendencia[] = [
                'mes'      => $clave,
                'monto'    => (float) ($registro->monto ?? 0) + (float) ($registroPack->monto ?? 0),
                'cantidad' => (int) ($registro->cantidad ?? 0) + (int) ($registroPack->cantidad ?? 0),
            ];
        }

        return response()->json(['success' => true, 'data' => [
            'mrr_total'          => round(array_sum($mrrPorPlan), 2),
            'mrr_por_plan'       => array_map(fn($v) => round($v, 2), $mrrPorPlan),
            'empresas_por_plan'  => $empresasPorPlan,
            'tendencia_mensual'  => $tendencia,
        ]]);
    }

    // POST /super-admin/empresas/{id}/impersonar
    // Emite un JWT para un usuario de la empresa (por defecto el más antiguo,
    // normalmente el dueño) para que el super admin pueda dar soporte sin
    // pedirle la contraseña al cliente. Queda logueado dado lo sensible de la
    // operación — quién impersonó a quién y cuándo.
    public function impersonar(Request $request, $id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $empresa = Empresa::findOrFail($id);

        $usuarioObjetivo = $request->filled('id_usuario')
            ? User::where('empresa_id', $empresa->id)->findOrFail($request->id_usuario)
            : User::where('empresa_id', $empresa->id)->orderBy('created_at')->firstOrFail();

        if ($usuarioObjetivo->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'No se puede impersonar a otro super admin'], 403);
        }

        $superAdmin = auth()->user();

        Log::warning('Impersonación de empresa por super admin', [
            'super_admin_id'    => $superAdmin->nro_usu,
            'super_admin_email' => $superAdmin->email,
            'empresa_id'        => $empresa->id,
            'empresa_nombre'    => $empresa->nombre,
            'usuario_id'        => $usuarioObjetivo->nro_usu,
            'usuario_email'     => $usuarioObjetivo->email,
        ]);
        $this->registrarActividad('impersonar', $empresa, "Como {$usuarioObjetivo->des_usu} ({$usuarioObjetivo->email})");

        $token = JWTAuth::fromUser($usuarioObjetivo);
        $usuarioObjetivo->load(['rol.permisos', 'tipoUsuario', 'permisos', 'empresa', 'sucursal']);

        return response()->json([
            'success'       => true,
            'access_token'  => $token,
            'token_type'    => 'bearer',
            'expires_in'    => config('jwt.ttl') * 60,
            'user'          => $usuarioObjetivo,
            'permisos'      => $usuarioObjetivo->obtenerTodosLosPermisos(),
            'impersonando'  => [
                'empresa'     => $empresa->nombre,
                'admin_email' => $superAdmin->email,
            ],
        ]);
    }

    // GET /super-admin/actividad — historial de acciones del super admin
    // (auditoría). Filtrable por empresa para revisar solo lo que pasó con
    // un cliente puntual.
    public function actividad(Request $request): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $query = SuperAdminLog::orderBy('created_at', 'desc');
        if ($request->filled('empresa_id')) {
            $query->where('empresa_id', $request->empresa_id);
        }
        if ($request->filled('desde')) {
            $query->whereDate('created_at', '>=', $request->desde);
        }
        if ($request->filled('hasta')) {
            $query->whereDate('created_at', '<=', $request->hasta);
        }

        $logs = $query->limit($request->limit ?? 200)->get()->map(fn($l) => [
            'id'                => $l->id,
            'super_admin_email' => $l->super_admin_email,
            'empresa_id'        => $l->empresa_id,
            'empresa_nombre'    => $l->empresa_nombre,
            'accion'            => $l->accion,
            'detalle'           => $l->detalle,
            'fecha'             => $l->created_at,
        ]);

        return response()->json(['success' => true, 'data' => $logs]);
    }
}
