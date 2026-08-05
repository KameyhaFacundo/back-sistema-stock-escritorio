<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('id_producto')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('id_sucursal')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->decimal('cantidad', 12, 2)->default(0);
            $table->date('fecha_vencimiento')->nullable();
            $table->foreignId('id_compra')->nullable()->constrained('compras')->nullOnDelete();
            $table->foreignId('id_usuario')->nullable()->constrained('users', 'nro_usu')->nullOnDelete();
            $table->timestamps();

            $table->index(['id_producto', 'id_sucursal']);
            $table->index('fecha_vencimiento');
            $table->index('cantidad');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes');
    }
};
