<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPlanLimits;
use App\Models\Sucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SucursalesController extends Controller
{
    use ChecksPlanLimits;

    public function index(): JsonResponse
    {
        $sucursales = Sucursal::orderBy('es_principal', 'desc')->orderBy('nombre')->get();

        return response()->json(['success' => true, 'data' => $sucursales]);
    }

    public function store(Request $request): JsonResponse
    {
        $empresa = auth('api')->user()->empresa;

        $validated = $request->validate([
            'nombre'    => 'required|string|max:150',
            'direccion' => 'nullable|string|max:255',
            'telefono'  => 'nullable|string|max:50',
            'activo'    => 'nullable|boolean',
        ]);

        DB::beginTransaction();
        try {
            if ($empresa) {
                $empresa = $this->lockEmpresa($empresa);
                if ($resp = $this->limitePlanExcedido($empresa, 'sucursales', Sucursal::count())) {
                    DB::rollBack();
                    return $resp;
                }
            }

            $sucursal = Sucursal::create($validated);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sucursal creada correctamente',
                'data'    => $sucursal,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al crear la sucursal', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $sucursal = Sucursal::findOrFail($id);

        $validated = $request->validate([
            'nombre'    => 'sometimes|string|max:150',
            'direccion' => 'sometimes|nullable|string|max:255',
            'telefono'  => 'sometimes|nullable|string|max:50',
            'activo'    => 'sometimes|boolean',
        ]);

        $sucursal->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Sucursal actualizada correctamente',
            'data'    => $sucursal,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $sucursal = Sucursal::findOrFail($id);

        if ($sucursal->es_principal) {
            return response()->json(['success' => false, 'message' => 'No se puede eliminar la sucursal principal'], 400);
        }

        if ($sucursal->stocks()->where('stock', '>', 0)->exists()) {
            return response()->json(['success' => false, 'message' => 'No se puede eliminar una sucursal con stock. Transferí el stock a otra sucursal primero.'], 400);
        }

        if (DB::table('users')->where('id_sucursal', $sucursal->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'No se puede eliminar: hay usuarios asignados a esta sucursal. Reasignalos primero desde Usuarios.'], 400);
        }

        // Ventas/compras/turnos/movimientos con id_sucursal apuntando acá se quedarían
        // sin sucursal (el FK es nullOnDelete) — mejor no perder ese historial.
        $tieneHistorial = DB::table('ventas')->where('id_sucursal', $sucursal->id)->exists()
            || DB::table('compras')->where('id_sucursal', $sucursal->id)->exists()
            || DB::table('turnos')->where('id_sucursal', $sucursal->id)->exists()
            || DB::table('movimientos_stock')->where('id_sucursal', $sucursal->id)->exists();

        if ($tieneHistorial) {
            return response()->json(['success' => false, 'message' => 'No se puede eliminar: esta sucursal tiene ventas, compras o movimientos registrados. Desactivala en vez de borrarla.'], 400);
        }

        $sucursal->delete();

        return response()->json(['success' => true, 'message' => 'Sucursal eliminada correctamente']);
    }
}
