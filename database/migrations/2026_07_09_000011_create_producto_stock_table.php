<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('id_producto')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('id_sucursal')->constrained('sucursales')->cascadeOnDelete();
            $table->integer('stock')->default(0);
            $table->integer('stock_minimo')->default(5);
            $table->timestamps();

            $table->unique(['id_producto', 'id_sucursal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_stock');
    }
};
