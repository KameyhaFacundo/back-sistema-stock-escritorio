<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Desglose real de una venta pagada con "varios métodos" (ver variosPagos
     * en Home.jsx) — sin esto, metodo_pago en ventas solo guarda el PRIMER
     * método cargado, y no hay forma de saber después cuánto de esa venta fue
     * efectivo de verdad para poder revertirlo bien al anular/devolver.
     * Distinta de pagos_cliente (PagoCliente), que es para cobros de fiado
     * hechos DESPUÉS de la venta, no el desglose del cobro original.
     */
    public function up(): void
    {
        Schema::create('pagos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_venta')->constrained('ventas')->cascadeOnDelete();
            $table->string('metodo', 50);
            $table->decimal('monto', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_venta');
    }
};
