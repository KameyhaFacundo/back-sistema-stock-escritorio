<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPlanLimits;
use App\Models\Categoria;
use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IaController extends Controller
{
    use ChecksPlanLimits;

    public function sugerencias(Request $request): JsonResponse
    {
        $request->validate([
            'accion'   => 'required|in:precio,categoria',
            'nombre'   => 'required|string|min:2',
            'costo'    => 'required_if:accion,precio|numeric|min:0',
            'categoria' => 'nullable|string',
        ]);

        $empresa = auth()->user()->empresa;
        if ($empresa && ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'ia',
            'Las sugerencias con IA están disponibles en el plan IA. Actualizá tu plan para usarlas.'
        ))) {
            return $resp;
        }

        if (!config('services.gemini.api_key')) {
            return response()->json([
                'success' => false,
                'message' => 'Las sugerencias con IA no están disponibles: falta configurar la clave de Gemini.',
            ], 503);
        }

        $gemini = new GeminiService();
        $nombre = $request->nombre;

        if ($request->accion === 'precio') {
            $precio = $gemini->sugerirPrecio(
                (float) $request->costo,
                $nombre,
                $request->categoria
            );

            return response()->json([
                'success' => true,
                'data'    => ['precio_sugerido' => $precio],
            ]);
        }

        // categoría
        $categorias = Categoria::select('categoria')->pluck('categoria')->toArray();
        $sugerida = $gemini->sugerirCategoria($nombre, $categorias);

        return response()->json([
            'success' => true,
            'data'    => ['categoria_sugerida' => $sugerida],
        ]);
    }
}
