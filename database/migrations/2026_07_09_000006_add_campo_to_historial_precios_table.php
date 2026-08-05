<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * historial_precios pasa a trackear también cambios de costo (no solo
     * precio de venta) — importante para auditar el margen, no solo lo que
     * paga el cliente. Se reutilizan las columnas precio_anterior/precio_nuevo
     * como "valor anterior/nuevo" genérico para no romper las filas ya
     * existentes (todas quedan implícitamente campo='precio').
     */
    public function up(): void
    {
        Schema::table('historial_precios', function (Blueprint $table) {
            $table->string('campo', 20)->default('precio')->after('id_producto');
        });
    }

    public function down(): void
    {
        Schema::table('historial_precios', function (Blueprint $table) {
            $table->dropColumn('campo');
        });
    }
};
