<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_producto')->nullable()->constrained('productos')->nullOnDelete();
            $table->string('producto');
            $table->string('codigo', 100)->nullable();
            $table->enum('tipo', ['venta', 'compra', 'ajuste', 'transferencia_salida', 'transferencia_entrada']);
            $table->string('sub_tipo', 255)->nullable();
            $table->integer('cantidad');
            $table->string('nota', 500)->nullable();
            $table->date('fecha');
            $table->string('hora', 10)->nullable();
            $table->timestamps();

            $table->index(['fecha', 'tipo']);
            $table->index('id_producto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_stock');
    }
};
