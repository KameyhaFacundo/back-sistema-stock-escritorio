<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateTalleRequest;
use App\Http\Requests\UpdateTalleRequest;
use App\Models\Talle;
use App\Services\VarianteProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TallesController extends Controller
{
    public function __construct(private VarianteProductoService $varianteService) {}

    public function index(Request $request): JsonResponse
    {
        $query = Talle::query();

        if ($request->filled('id_grupo_talle')) {
            $query->where('id_grupo_talle', $request->id_grupo_talle);
        }

        $talles = $query->orderBy('orden')->orderBy('valor')->get();

        return response()->json([
            'success' => true,
            'data' => $talles,
        ]);
    }

    public function store(CreateTalleRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $talle = Talle::create($request->validated());

            // Un local con muchos productos usando esta curva espera que el
            // talle nuevo se refleje en todos ellos, no solo en los que se
            // vuelvan a guardar a mano — ver VarianteProductoService.
            $this->varianteService->propagarNuevoTalle($talle);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Talle creado correctamente',
                'data' => $talle,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el talle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateTalleRequest $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $talle = Talle::findOrFail($id);
            $talle->update($request->validated());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Talle actualizado correctamente',
                'data' => $talle,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el talle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $talle = Talle::findOrFail($id);

        if ($talle->productos()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el talle porque hay productos que lo usan',
            ], 400);
        }

        $talle->delete();

        return response()->json([
            'success' => true,
            'message' => 'Talle eliminado correctamente',
        ]);
    }
}
