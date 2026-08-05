<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// La tabla `lotes` se creó vacía (2026_07_10_000002) y desde entonces es la
// única fuente que StockService::disponible()/restar() usa para validar
// ventas, bajas y transferencias — pero el stock que ya existía en
// `producto_stock` (todo lo cargado antes de que existiera el sistema de
// lotes) nunca se migró ahí. Resultado: para cualquier producto con stock
// previo, disponible() daba 0 y el sistema rechazaba toda venta/baja/
// transferencia como si no hubiera stock, aunque `producto_stock.stock`
// (lo que ve el usuario en pantalla) mostrara el número real.
// Este backfill crea, por cada fila de producto_stock con stock > 0, un lote
// de apertura sin fecha de vencimiento (no se conoce la fecha real de ese
// stock histórico) para que ambas fuentes queden consistentes.
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
