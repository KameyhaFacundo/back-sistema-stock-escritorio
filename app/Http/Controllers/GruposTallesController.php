<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateGrupoTalleRequest;
use App\Http\Requests\UpdateGrupoTalleRequest;
use App\Models\GrupoTalle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GruposTallesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $empresaId = auth()->user()->empresa_id;
        $version   = Cache::get("grupos_talles:list:version:{$empresaId}", 1);
        $cacheKey  = "grupos_talles:list:{$empresaId}:v{$version}:" . md5(json_encode($request->only(['search', 'per_page'])));

        $grupos = Cache::remember($cacheKey, 300, function () use ($request) {
            $query = GrupoTalle::with('talles');

            if ($request->has('search') && $request->search) {
                $query->where('nombre', 'like', "%{$request->search}%");
            }

            return $query->orderBy('nombre')->paginate($request->per_page ?? 50);
        });

        return response()->json([
            'success' => true,
            'data' => $grupos,
        ]);
    }

    public function show($id): JsonResponse
    {
        $grupo = GrupoTalle::with('talles')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $grupo,
        ]);
    }

    public function store(CreateGrupoTalleRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $grupo = GrupoTalle::create($request->validated());

            DB::commit();
            $this->clearListCache();

            return response()->json([
                'success' => true,
                'message' => 'Grupo de talles creado correctamente',
                'data' => $grupo,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el grupo de talles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateGrupoTalleRequest $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $grupo = GrupoTalle::findOrFail($id);
            $grupo->update($request->validated());

            DB::commit();
            $this->clearListCache();

            return response()->json([
                'success' => true,
                'message' => 'Grupo de talles actualizado correctamente',
                'data' => $grupo,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el grupo de talles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $grupo = GrupoTalle::findOrFail($id);

        if ($grupo->productos()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el grupo porque hay productos que lo usan',
            ], 400);
        }

        $grupo->delete();

        $this->clearListCache();

        return response()->json([
            'success' => true,
            'message' => 'Grupo de talles eliminado correctamente',
        ]);
    }

    private function clearListCache(): void
    {
        $empresaId = auth()->user()->empresa_id;
        $versionKey = "grupos_talles:list:version:{$empresaId}";
        Cache::forever($versionKey, Cache::get($versionKey, 1) + 1);
    }
}
