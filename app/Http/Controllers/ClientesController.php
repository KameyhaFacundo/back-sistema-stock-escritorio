<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Cliente::query();

        // Filtro por búsqueda
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('persona', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filtro por CUIT
        if ($request->has('cuit') && $request->cuit) {
            $query->where('cuit', 'like', "%{$request->cuit}%");
        }

        // Filtro por estado
        if ($request->has('estado') && $request->estado !== null) {
            $query->where('estado', $request->estado);
        }

        $clientes = $query->orderBy('persona')->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $clientes,
        ]);
    }

    public function show($id): JsonResponse
    {
        $cliente = Cliente::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $cliente,
        ]);
    }

    // GET /clientes/{id}/puntos — saldo actual + historial de puntos
    public function puntos($id): JsonResponse
    {
        $cliente = Cliente::findOrFail($id);
        $movimientos = $cliente->movimientosPuntos()->latest()->limit(100)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'saldo'       => $cliente->puntos,
                'movimientos' => $movimientos,
            ],
        ]);
    }

    public function store(CreateClienteRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $cliente = Cliente::create($request->validated());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cliente creado correctamente',
                'data' => $cliente,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el cliente',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateClienteRequest $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $cliente = Cliente::findOrFail($id);
            $cliente->update($request->validated());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cliente actualizado correctamente',
                'data' => $cliente,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el cliente',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $cliente = Cliente::findOrFail($id);

        // Validar que no tenga ventas asociadas
        if ($cliente->ventas()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el cliente porque tiene ventas asociadas',
            ], 400);
        }

        $cliente->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cliente eliminado correctamente',
        ]);
    }
}
