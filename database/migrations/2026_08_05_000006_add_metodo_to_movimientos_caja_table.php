<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los movimientos manuales de caja (Ingreso/Egreso) eran siempre en
     * efectivo — ahora también pueden ser por transferencia, para llevar la
     * cuenta de plata que entra/sale del negocio sin pasar por la caja
     * física. Default 'efectivo' para que las filas existentes (todas eran
     * en efectivo) sigan afectando el arqueo exactamente igual que antes.
     */
    public function up(): void
    {
        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->enum('metodo', ['efectivo', 'transferencia'])->default('efectivo')->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->dropColumn('metodo');
        });
    }
};
