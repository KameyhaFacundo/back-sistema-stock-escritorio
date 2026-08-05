<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sin empresa_id propio, igual que lineas_ventas — escopea a través de
        // id_devolucion_venta (devoluciones_venta sí tiene HasTenant).
        Schema::create('devoluciones_venta_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_devolucion_venta')->constrained('devoluciones_venta')->cascadeOnDelete();
            $table->unsignedBigInteger('id_linea_venta');
            $table->foreign('id_linea_venta')->references('id_linea')->on('lineas_ventas')->cascadeOnDelete();
            $table->unsignedBigInteger('id_producto');
            $table->foreign('id_producto')->references('id')->on('productos')->cascadeOnDelete();
            // Cantidad devuelta de esa línea en esta devolución puntual — no es
            // un contador acumulado, "cuánto ya se devolvió en total" se calcula
            // sumando estas filas para la línea (ver DevolucionVentaService).
            $table->decimal('cantidad', 12, 2);
            $table->decimal('precio_unitario', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devoluciones_venta_lineas');
    }
};
