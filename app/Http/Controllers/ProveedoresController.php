<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateProveedorRequest;
use App\Http\Requests\UpdateProveedorRequest;
use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProveedoresController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $empresaId = auth()->user()->empresa_id;
        $version   = Cache::get("proveedores:list:version:{$empresaId}", 1);
        $cacheKey  = "proveedores:list:{$empresaId}:v{$version}:" . md5(json_encode($request->only(['search', 'cuit', 'estado', 'per_page'])));

        $proveedores = Cache::remember($cacheKey, 300, function () use ($request) {
            $query = Proveedor::query();

            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('persona', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($request->has('cuit') && $request->cuit) {
                $query->where('cuit', 'like', "%{$request->cuit}%");
            }

            if ($request->has('estado') && $request->estado !== null) {
                $query->where('estado', $request->estado);
            }

            return $query->orderBy('persona')->paginate($request->per_page ?? 15);
        });

        return response()->json([
            'success' => true,
            'data' => $proveedores,
        ]);
    }

    public function show($id): JsonResponse
    {
        $proveedor = Proveedor::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $proveedor,
        ]);
    }

    public function store(CreateProveedorRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $proveedor = Proveedor::create($request->validated());

            DB::commit();
            $this->clearListCache();

            return response()->json([
                'success' => true,
                'message' => 'Proveedor creado correctamente',
                'data' => $proveedor,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el proveedor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateProveedorRequest $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $proveedor = Proveedor::findOrFail($id);
            $proveedor->update($request->validated());

            DB::commit();
            $this->clearListCache();

            return response()->json([
                'success' => true,
                'message' => 'Proveedor actualizado correctamente',
                'data' => $proveedor,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el proveedor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $proveedor = Proveedor::findOrFail($id);

        // Validar que no tenga compras asociadas
        if ($proveedor->compras()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el proveedor porque tiene compras asociadas',
            ], 400);
        }

        $proveedor->delete();
        $this->clearListCache();

        return response()->json([
            'success' => true,
            'message' => 'Proveedor eliminado correctamente',
        ]);
    }

    private function clearListCache(): void
    {
        $empresaId = auth()->user()->empresa_id;
        $versionKey = "proveedores:list:version:{$empresaId}";
        Cache::forever($versionKey, Cache::get($versionKey, 1) + 1);
    }
}
