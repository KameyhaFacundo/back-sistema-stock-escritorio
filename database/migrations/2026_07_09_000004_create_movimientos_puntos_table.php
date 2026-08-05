<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_puntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('id_cliente')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('id_venta')->nullable()->constrained('ventas')->nullOnDelete();
            $table->enum('tipo', ['ganado', 'canjeado']);
            // Positivo si se ganaron, negativo si se canjearon — igual que el signo de
            // cantidad en movimientos_stock.
            $table->integer('puntos');
            $table->unsignedInteger('saldo_posterior');
            $table->string('nota', 255)->nullable();
            $table->timestamps();

            $table->index(['id_cliente', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_puntos');
    }
};
