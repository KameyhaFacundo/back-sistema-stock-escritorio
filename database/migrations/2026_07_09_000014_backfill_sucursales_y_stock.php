<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Por cada empresa existente crea una sucursal "Casa Central", migra el stock
     * plano de productos a producto_stock en esa sucursal, y liga los registros
     * existentes (usuarios, ventas, compras, turnos, movimientos_stock) a ella —
     * así ninguna empresa pierde datos ni cambia de comportamiento al activarse
     * multi-sucursal.
     */
    public function up(): void
    {
        $empresas = DB::table('empresas')->select('id')->get();

        foreach ($empresas as $empresa) {
            $idSucursal = DB::table('sucursales')->insertGetId([
                'empresa_id'   => $empresa->id,
                'nombre'       => 'Casa Central',
                'activo'       => true,
                'es_principal' => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            $productos = DB::table('productos')
                ->where('empresa_id', $empresa->id)
                ->select('id', 'stock', 'stock_minimo')
                ->get();

            foreach ($productos as $producto) {
                DB::table('producto_stock')->insert([
                    'empresa_id'   => $empresa->id,
                    'id_producto'  => $producto->id,
                    'id_sucursal'  => $idSucursal,
                    'stock'        => $producto->stock,
                    'stock_minimo' => $producto->stock_minimo,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }

            foreach (['users' => 'nro_usu', 'ventas' => 'id', 'compras' => 'id', 'turnos' => 'id', 'movimientos_stock' => 'id'] as $table => $pk) {
                DB::table($table)
                    ->where('empresa_id', $empresa->id)
                    ->whereNull('id_sucursal')
                    ->update(['id_sucursal' => $idSucursal]);
            }
        }
    }

    public function down(): void
    {
        // No reversible de forma segura (perdería la asociación producto→sucursal
        // si hubiera más de una sucursal para entonces) — se deja vacío a propósito,
        // igual que el resto de migraciones de backfill de este repo.
    }
};
