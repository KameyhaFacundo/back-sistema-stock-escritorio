<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte para líneas de venta de "monto libre" (un cargo sin producto real,
 * ej. "Servicio de instalación") — hasta ahora el front las cobraba e imprimía
 * en el ticket pero las descartaba antes de mandar la venta al backend, así
 * que la venta y la caja quedaban acreditadas por menos de lo que en
 * realidad se cobró. id_producto pasa a ser opcional, y se agrega `nombre`
 * para que esas líneas queden identificadas (no hay Producto del que leerlo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->dropForeign(['id_producto']);
        });

        // Sin doctrine/dbal en el proyecto: MODIFY vía SQL directo en vez de ->change().
        DB::statement('ALTER TABLE lineas_ventas MODIFY id_producto BIGINT UNSIGNED NULL');

        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->string('nombre')->nullable()->after('id_producto');
            $table->foreign('id_producto')->references('id')->on('productos')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->dropForeign(['id_producto']);
            $table->dropColumn('nombre');
        });

        DB::statement('ALTER TABLE lineas_ventas MODIFY id_producto BIGINT UNSIGNED NOT NULL');

        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->foreign('id_producto')->references('id')->on('productos')->onDelete('cascade');
        });
    }
};
