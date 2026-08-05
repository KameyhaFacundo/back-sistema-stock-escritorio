<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// La backfill original (2026_07_10_000003) tapó el hueco para el stock que ya
// existía en ese momento, pero ProductosController::store() seguía creando la
// fila de producto_stock del stock inicial de un producto nuevo con un
// ProductoStock::create() directo (sin pasar por StockService::agregar()),
// así que cualquier producto creado después de esa migración con stock
// inicial > 0 volvió a quedar sin lote — mismo síntoma: la lista de Productos
// muestra el stock bien, pero ventas/movimientos lo ven en 0 porque
// disponible() suma de `lotes`. Ya se corrigió el bug en el controller; este
// backfill (misma lógica idempotente que el original: solo tapa la
// diferencia, nunca duplica) repara los productos que quedaron desincronizados
// mientras tanto.
return new class extends Migration
{
    public function up(): void
    {
        $filas = DB::table('producto_stock')->where('stock', '>', 0)->get();

        foreach ($filas as $fila) {
            $sumaLotes = DB::table('lotes')
                ->where('id_producto', $fila->id_producto)
                ->where('id_sucursal', $fila->id_sucursal)
                ->where('cantidad', '>', 0)
                ->sum('cantidad');

            $faltante = $fila->stock - $sumaLotes;
            if ($faltante <= 0) continue;

            DB::table('lotes')->insert([
                'empresa_id'        => $fila->empresa_id,
                'id_producto'       => $fila->id_producto,
                'id_sucursal'       => $fila->id_sucursal,
                'cantidad'          => $faltante,
                'fecha_vencimiento' => null,
                'id_compra'         => null,
                'id_usuario'        => null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No se puede distinguir de forma segura un lote de apertura creado acá
        // de uno legítimo cargado después — no se revierte automáticamente.
    }
};
