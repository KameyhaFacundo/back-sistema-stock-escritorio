<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PIN corto (4-6 dígitos) para autorizar descuentos desde el POS sin
     * tener que tipear la contraseña completa de login en la pantalla del
     * cajero (ver VentasController::autorizarDescuento). Nullable — no todos
     * los usuarios con aplicar-descuento-ventas necesariamente lo configuran;
     * mientras no lo tengan, simplemente no aparecen como candidatos válidos
     * para autorizar (ver el where en autorizarDescuento).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin');
        });
    }
};
