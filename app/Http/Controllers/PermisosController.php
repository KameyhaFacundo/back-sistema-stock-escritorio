<?php

namespace App\Http\Controllers;

use App\Models\HistorialPermiso;
use App\Models\Permiso;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermisosController extends Controller
{
    public function index(): JsonResponse
    {
        $permisos = Permiso::orderBy('grupo')->orderBy('nombre')->get();

        return response()->json([
            'success' => true,
            'data' => $permisos,
        ]);
    }

    public function indexAgrupados(): JsonResponse
    {
        $permisos = Permiso::orderBy('nombre')->get()->groupBy('grupo');

        return response()->json([
            'success' => true,
            'data' => $permisos,
        ]);
    }

    public function misPermisos(): JsonResponse
    {
        $user = auth()->user();
        $permisos = $user->obtenerTodosLosPermisos();

        return response()->json([
            'success' => true,
            'data' => $permisos,
        ]);
    }

    public function permisosUsuario($id): JsonResponse
    {
        // Scopeado por empresa — User no tiene HasTenant (sin esto, cualquiera con
        // permiso list-usuarios podía ver los permisos de un usuario de OTRA empresa
        // adivinando su nro_usu).
        $user = User::with(['permisos', 'rol.permisos'])
            ->where('empresa_id', auth()->user()->empresa_id)
            ->findOrFail($id);

        $permisosDirectos = $user->permisos;
        $permisosRol = $user->rol ? $user->rol->permisos : collect();

        return response()->json([
            'success' => true,
            'data' => [
                'permisos_directos' => $permisosDirectos,
                'permisos_rol' => $permisosRol,
                'todos_permisos' => $user->obtenerTodosLosPermisos(),
            ],
        ]);
    }

    public function agregarPermiso(Request $request, $id): JsonResponse
    {
        $request->validate([
            'permisos' => 'required|array',
            'permisos.*' => 'exists:permisos,id',
        ]);

        // Scopeado por empresa — sin esto, cualquiera con permiso assign-permisos
        // podía otorgar o quitar permisos al usuario de OTRA empresa adivinando
        // su nro_usu (User no tiene HasTenant, no hay scope automático).
        $user = User::where('empresa_id', auth()->user()->empresa_id)->findOrFail($id);
        $codigosAntes = $user->permisos()->pluck('codigo')->all();

        $user->permisos()->sync($request->permisos);

        $user->load('permisos');
        $codigosDespues = $user->permisos->pluck('codigo')->all();

        $agregados = array_values(array_diff($codigosDespues, $codigosAntes));
        $quitados  = array_values(array_diff($codigosAntes, $codigosDespues));

        if ($agregados || $quitados) {
            HistorialPermiso::create([
                'empresa_id'           => auth()->user()->empresa_id,
                'id_usuario_afectado'  => $user->nro_usu,
                'id_usuario_actor'     => auth()->id(),
                'permisos_agregados'   => $agregados ?: null,
                'permisos_quitados'    => $quitados ?: null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Permisos actualizados correctamente',
            'data' => $user->permisos,
        ]);
    }

    // GET /permisos/usuario/{id}/historial — quién cambió qué permisos y cuándo
    public function historialUsuario($id): JsonResponse
    {
        User::where('empresa_id', auth()->user()->empresa_id)->findOrFail($id);

        $historial = HistorialPermiso::with('usuarioActor:nro_usu,des_usu')
            ->where('id_usuario_afectado', $id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'data' => $historial]);
    }
}
