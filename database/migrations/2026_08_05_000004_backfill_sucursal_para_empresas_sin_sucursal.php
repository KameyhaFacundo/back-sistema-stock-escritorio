<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * La migración 2026_07_09_000014_backfill_sucursales_y_stock solo cubrió las
     * empresas que ya existían en ese momento — cualquier empresa creada después
     * (por ejemplo por UsuarioSeeder en una instalación nueva del launcher de
     * escritorio) se quedó sin ninguna sucursal, y su usuario admin sin
     * id_sucursal, bloqueando cualquier acción que lo requiera (crear productos,
     * abrir caja, etc. — ver los checks "Tu usuario no tiene una sucursal
     * asignada" repartidos en varios controllers).
     */
    public function up(): void
    {
        $empresasSinSucursal = DB::table('empresas')
            ->whereNotIn('id', function ($q) {
                $q->select('empresa_id')->from('sucursales')->whereNotNull('empresa_id');
            })
            ->select('id')
            ->get();

        foreach ($empresasSinSucursal as $empresa) {
            $idSucursal = DB::table('sucursales')->insertGetId([
                'empresa_id'   => $empresa->id,
                'nombre'       => 'Casa Central',
                'activo'       => true,
                'es_principal' => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

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
        // No reversible de forma segura, igual que el resto de migraciones de
        // backfill de este repo.
    }
};
