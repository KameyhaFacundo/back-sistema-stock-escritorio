<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPlanLimits;
use App\Models\PlantillaEtiqueta;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EtiquetaController extends Controller
{
    use ChecksPlanLimits;

    // Listar y borrar quedan siempre permitidos (una empresa que bajó de plan
    // no pierde la visibilidad de lo que ya tenía, mismo criterio que
    // "desactivar" en CatalogoController) — pero crear/editar plantillas es
    // la función paga en sí, y sin permiso.verify:view-etiquetas + este
    // chequeo de plan, cualquier empresa en Esencial podía usarla igual (el
    // rol admin trae view-etiquetas por default, ver RolSeeder).
    public function index(): JsonResponse
    {
        $empresaId = auth()->user()->empresa_id;
        $plantillas = PlantillaEtiqueta::where('empresa_id', $empresaId)
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json($plantillas);
    }

    public function store(Request $request): JsonResponse
    {
        $empresa = auth()->user()->empresa;
        if ($empresa && ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'etiquetas',
            'Las plantillas de etiquetas están disponibles desde el plan Pro. Actualizá tu plan para crear una.'
        ))) {
            return $resp;
        }

        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'config' => 'required|array',
        ]);

        $validated['empresa_id'] = auth()->user()->empresa_id;
        $plantilla = PlantillaEtiqueta::create($validated);

        return response()->json($plantilla, 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $empresa = auth()->user()->empresa;
        if ($empresa && ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'etiquetas',
            'Las plantillas de etiquetas están disponibles desde el plan Pro. Actualizá tu plan para editarla.'
        ))) {
            return $resp;
        }

        $plantilla = PlantillaEtiqueta::where('empresa_id', auth()->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'config' => 'sometimes|required|array',
        ]);

        $plantilla->update($validated);

        return response()->json($plantilla);
    }

    public function destroy($id): JsonResponse
    {
        $plantilla = PlantillaEtiqueta::where('empresa_id', auth()->user()->empresa_id)->findOrFail($id);
        $plantilla->delete();

        return response()->json(['success' => true, 'message' => 'Plantilla eliminada']);
    }
}
