<?php

namespace App\Http\Controllers;

use App\Models\CarritoVaciado;
use Illuminate\Http\Request;

class CarritoVaciadoController extends Controller
{
    /**
     * Registra que se vació el carrito en el POS con lo que tenía cargado —
     * cualquier usuario con acceso al POS puede generar este registro (no
     * requiere un permiso especial, es automático al tocar "Vaciar carrito");
     * lo que sí está restringido es poder LISTARLOS (ver index()).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.nombre'     => 'required|string|max:255',
            'items.*.codigo'     => 'nullable|string|max:100',
            'items.*.cantidad'   => 'required|numeric|min:0',
            'items.*.precio'     => 'required|numeric|min:0',
            'total'              => 'required|numeric|min:0',
        ]);

        $carrito = CarritoVaciado::create([
            'id_sucursal' => auth()->user()?->id_sucursal,
            'id_usuario'  => auth()->user()?->nro_usu,
            'items'       => $validated['items'],
            'total'       => $validated['total'],
        ]);

        return response()->json(['success' => true, 'data' => $carrito]);
    }

    public function index(Request $request)
    {
        $query = CarritoVaciado::with(['usuario:nro_usu,des_usu', 'sucursal:id,nombre'])->latest();

        if ($fecha = $request->fecha) {
            $query->whereDate('created_at', $fecha);
        }

        if ($idSucursal = $request->id_sucursal) {
            $query->where('id_sucursal', $idSucursal);
        }

        $perPage = min((int) ($request->per_page ?? 100), 500);
        $data    = $query->paginate($perPage);

        return response()->json(['success' => true, 'data' => $data]);
    }
}
