<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Qué tarjetas de método de pago mostrar en el arqueo de caja (Resumen del
     * turno) — no todos los negocios usan los 5 métodos (efectivo, tarjeta,
     * transferencia, qr, fiado) y las que nunca se usan son ruido. NULL =
     * mostrar las 5 (default, no cambia nada para una empresa existente que
     * nunca configuró esto).
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->json('arqueo_metodos_visibles')->nullable()->after('puntos_valor_pesos');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('arqueo_metodos_visibles');
        });
    }
};
