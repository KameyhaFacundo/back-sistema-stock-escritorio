<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // productos.id_proveedor sigue siendo el proveedor PRINCIPAL (el que se
        // usa para filtrar y para precargar en Compras) — esta tabla es solo para
        // los proveedores ALTERNATIVOS de un producto, cada uno con su propio
        // costo/código opcional (puede variar de lo que cobra el principal).
        Schema::create('producto_proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('id_producto')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('id_proveedor')->constrained('proveedores')->cascadeOnDelete();
            $table->decimal('costo', 12, 2)->nullable();
            $table->string('codigo_proveedor')->nullable();
            $table->timestamps();

            $table->unique(['id_producto', 'id_proveedor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_proveedores');
    }
};
