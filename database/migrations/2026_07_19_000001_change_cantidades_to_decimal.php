<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Primer paso (de 3) para poder vender productos por peso/longitud —
 * hoy toda cantidad es entera, lo que hace imposible registrar "0.5 kg"
 * o "2.5 m". Este cambio es solo de esquema: ningún FormRequest permite
 * todavía mandar un valor fraccionario, así que ningún tenant puede
 * generar un dato decimal a partir de este deploy — el objetivo es
 * verificar el ALTER TABLE de forma aislada antes de tocar la lógica de
 * negocio (StockService, combos, validaciones) en un paso siguiente.
 *
 * decimal(12,2) replica la precisión que ya usa `lotes.cantidad` desde
 * antes — no se inventa un formato nuevo.
 *
 * down(): revierte a integer. Solo es seguro ejecutarlo ANTES de que
 * exista algún dato fraccionario real (es decir, antes de que las fases
 * 2 y 3 de esta función lleguen a producción) — una vez que una
 * ferretería real venda "0.5 kg", este rollback truncaría esos datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->decimal('cantidad', 12, 2)->change();
        });
        Schema::table('lineas_compras', function (Blueprint $table) {
            $table->decimal('cantidad', 12, 2)->change();
        });
        Schema::table('movimientos_stock', function (Blueprint $table) {
            $table->decimal('cantidad', 12, 2)->change();
        });
        Schema::table('combo_componentes', function (Blueprint $table) {
            $table->unsignedDecimal('cantidad', 12, 2)->default(1.00)->change();
        });
        Schema::table('producto_stock', function (Blueprint $table) {
            $table->decimal('stock', 12, 2)->default(0.00)->change();
            $table->decimal('stock_minimo', 12, 2)->default(5.00)->change();
        });
    }

    public function down(): void
    {
        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->integer('cantidad')->change();
        });
        Schema::table('lineas_compras', function (Blueprint $table) {
            $table->integer('cantidad')->change();
        });
        Schema::table('movimientos_stock', function (Blueprint $table) {
            $table->integer('cantidad')->change();
        });
        Schema::table('combo_componentes', function (Blueprint $table) {
            $table->unsignedInteger('cantidad')->default(1)->change();
        });
        Schema::table('producto_stock', function (Blueprint $table) {
            $table->integer('stock')->default(0)->change();
            $table->integer('stock_minimo')->default(5)->change();
        });
    }
};
