<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lineas_compras', function (Blueprint $table) {
            $table->id('id_linea');
            $table->unsignedBigInteger('id_compra');
            $table->unsignedBigInteger('id_producto');
            $table->decimal('precio_compra', 12, 2);
            $table->integer('cantidad');
            $table->timestamps();

            $table->foreign('id_compra')
                  ->references('id')
                  ->on('compras')
                  ->onDelete('cascade');

            $table->foreign('id_producto')
                  ->references('id')
                  ->on('productos')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lineas_compras');
    }
};
