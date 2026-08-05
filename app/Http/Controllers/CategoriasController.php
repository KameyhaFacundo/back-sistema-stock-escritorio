<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateCategoriaRequest;
use App\Http\Requests\UpdateCategoriaRequest;
use App\Models\Categoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CategoriasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $empresaId = auth()->user()->empresa_id;
        $version   = Cache::get("categorias:list:version:{$empresaId}", 1);
        $cacheKey  = "categorias:list:{$empresaId}:v{$version}:" . md5(json_encode($request->only(['search', 'per_page'])));

        $categorias = Cache::remember($cacheKey, 300, function () use ($request) {
            $query = Categoria::query();

            if ($request->has('search') && $request->search) {
                $query->where('categoria', 'like', "%{$request->search}%");
            }

            return $query->orderBy('categoria')->paginate($request->per_page ?? 15);
        });

        return response()->json([
            'success' => true,
            'data' => $categorias,
        ]);
    }

    public function show($id): JsonResponse
    {
        $categoria = Categoria::with('productos')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $categoria,
        ]);
    }

    public function store(CreateCategoriaRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $categoria = Categoria::create($request->validated());

            DB::commit();
            $this->clearListCache();

            return response()->json([
                'success' => true,
                'message' => 'Categoría creada correctamente',
                'data' => $categoria,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la categoría',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateCategoriaRequest $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $categoria = Categoria::findOrFail($id);
            $categoria->update($request->validated());

            DB::commit();
            $this->clearListCache();

            return response()->json([
                'success' => true,
                'message' => 'Categoría actualizada correctamente',
                'data' => $categoria,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la categoría',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $categoria = Categoria::findOrFail($id);

        // Validar que no tenga productos asociados
        if ($categoria->productos()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar la categoría porque tiene productos asociados',
            ], 400);
        }

        $categoria->delete();

        $this->clearListCache();

        return response()->json([
            'success' => true,
            'message' => 'Categoría eliminada correctamente',
        ]);
    }

    private function clearListCache(): void
    {
        $empresaId = auth()->user()->empresa_id;
        $versionKey = "categorias:list:version:{$empresaId}";
        Cache::forever($versionKey, Cache::get($versionKey, 1) + 1);
    }
}
