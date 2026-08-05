<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateRolRequest;
use App\Http\Requests\UpdateRolRequest;
use App\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RolesController extends Controller
{
    /**
     * Los roles son un catálogo GLOBAL compartido por toda la plataforma (3 fijos,
     * ver RolSeeder — no tienen empresa_id ni ningún scope de tenant), usados solo
     * como preset de permisos al crear/cambiar el rol de un usuario. El rol "admin"
     * trae todos los permisos por seed, así que el dueño de CUALQUIER empresa tiene
     * técnicamente update-roles/delete-roles/create-roles — sin este chequeo podía
     * editar o borrar el catálogo compartido y afectar a las demás empresas de la
     * plataforma. El front no tiene una pantalla de gestión de roles (solo lista el
     * dropdown en Usuarios), así que esto no le saca nada a nadie hoy.
     */
    private function checkSuperAdmin(): ?JsonResponse
    {
        if (!auth()->user()->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado'], 403);
        }
        return null;
    }

    public function index(): JsonResponse
    {
        $roles = Rol::with('permisos')->orderBy('nombre')->get();

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    public function show($id): JsonResponse
    {
        $rol = Rol::with('permisos')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $rol,
        ]);
    }

    public function store(CreateRolRequest $request): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        DB::beginTransaction();

        try {
            $rol = Rol::create([
                'codigo' => $request->codigo,
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion,
            ]);

            if ($request->has('permisos') && is_array($request->permisos)) {
                $rol->permisos()->sync($request->permisos);
            }

            DB::commit();

            $rol->load('permisos');

            return response()->json([
                'success' => true,
                'message' => 'Rol creado correctamente',
                'data' => $rol,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el rol',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateRolRequest $request, $id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        DB::beginTransaction();

        try {
            $rol = Rol::findOrFail($id);

            $rol->update($request->only(['codigo', 'nombre', 'descripcion']));

            if ($request->has('permisos')) {
                $rol->permisos()->sync($request->permisos ?? []);
            }

            DB::commit();

            $rol->load('permisos');

            return response()->json([
                'success' => true,
                'message' => 'Rol actualizado correctamente',
                'data' => $rol,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el rol',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        if ($err = $this->checkSuperAdmin()) return $err;

        $rol = Rol::findOrFail($id);

        if ($rol->usuarios()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el rol porque tiene usuarios asignados',
            ], 400);
        }

        $rol->permisos()->detach();
        $rol->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rol eliminado correctamente',
        ]);
    }
}
