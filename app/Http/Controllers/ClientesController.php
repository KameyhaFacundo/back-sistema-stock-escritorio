<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ClientesController extends Controller
{
    // Mismo patrón que Categorías/Proveedores (ver ProveedoresController::index) —
    // esta lista no tenía caché, a diferencia de sus hermanas, y el POS la pide
    // sin pasar por ningún hook de TanStack Query cacheado del lado del front
    // (ver Home.jsx), así que se re-pedía sin caché ninguna cada vez que un
    // cajero volvía a la pantalla de venta. El campo "puntos" puede quedar
    // hasta 5 min desactualizado en esta lista — no es un problema real: el
    // canje de puntos de una venta valida siempre contra el cliente fresco con
    // lock (ver VentaCreacionService::crear()), nunca contra esta caché.
    public function index(Request $request): JsonResponse
    {
        $empresaId = auth()->user()->empresa_id;
        $version   = Cache::get("clientes:list:version:{$empresaId}", 1);
        $cacheKey  = "clientes:list:{$empresaId}:v{$version}:" . md5(json_encode($request->only(['search', 'cuit', 'estado', 'per_page'])));

        $clientes = Cache::remember($cacheKey, 300, function () use ($request) {
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

            return $query->orderBy('persona')->paginate($request->per_page ?? 15);
        });

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
            $this->clearListCache();

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
            $this->clearListCache();

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
        $this->clearListCache();

        return response()->json([
            'success' => true,
            'message' => 'Cliente eliminado correctamente',
        ]);
    }

    private function clearListCache(): void
    {
        $empresaId = auth()->user()->empresa_id;
        $versionKey = "clientes:list:version:{$empresaId}";
        Cache::forever($versionKey, Cache::get($versionKey, 1) + 1);
    }
}
