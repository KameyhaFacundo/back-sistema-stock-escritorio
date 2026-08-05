<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sin empresa_id propio, igual que lineas_compras — escopea a través de
        // id_devolucion_compra (devoluciones_compra sí tiene HasTenant).
        Schema::create('devoluciones_compra_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_devolucion_compra')->constrained('devoluciones_compra')->cascadeOnDelete();
            $table->unsignedBigInteger('id_linea_compra');
            $table->foreign('id_linea_compra')->references('id_linea')->on('lineas_compras')->cascadeOnDelete();
            $table->unsignedBigInteger('id_producto');
            $table->foreign('id_producto')->references('id')->on('productos')->cascadeOnDelete();
            $table->decimal('cantidad', 12, 2);
            $table->decimal('precio_unitario', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devoluciones_compra_lineas');
    }
};
