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
        Schema::create('pagos_facturacion_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->onDelete('cascade');
            $table->string('pack');
            $table->unsignedInteger('cantidad');
            $table->decimal('monto', 12, 2)->nullable();
            // Unique desde el vamos (a diferencia de pagos_suscripcion, que lo
            // agregó en una migración aparte) — evita que un reintento del
            // webhook de Mercado Pago acredite el mismo pago dos veces.
            $table->string('payment_id')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagos_facturacion_packs');
    }
};
