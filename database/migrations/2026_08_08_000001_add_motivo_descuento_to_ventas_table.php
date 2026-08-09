<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Motivo que el cajero escribe cuando la venta lleva un descuento/recargo
     * manual (global o por línea) o un ítem de "monto libre" — quién tiene
     * permiso para aplicar un descuento ya estaba controlado, pero nada
     * dejaba un rastro de POR QUÉ lo aplicó en cada caso puntual. Ver
     * VentasController::store() para cuándo se exige.
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('motivo_descuento', 255)->nullable()->after('cuit');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('motivo_descuento');
        });
    }
};
