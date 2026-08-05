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
 *
 * SQLite no soporta dropForeign() (requeriría recrear la tabla a mano) — pero
 * tampoco hace falta ahí: Schema::table()->change() ya recrea la tabla entera
 * vía doctrine/dbal preservando la foreign key existente, así que el
 * drop/re-add manual solo se hace en MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('lineas_ventas', function (Blueprint $table) {
                $table->dropForeign(['id_producto']);
            });
        }

        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->unsignedBigInteger('id_producto')->nullable()->change();
        });

        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->string('nombre')->nullable()->after('id_producto');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('lineas_ventas', function (Blueprint $table) {
                $table->foreign('id_producto')->references('id')->on('productos')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('lineas_ventas', function (Blueprint $table) {
                $table->dropForeign(['id_producto']);
            });
        }

        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->dropColumn('nombre');
        });

        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->unsignedBigInteger('id_producto')->nullable(false)->change();
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('lineas_ventas', function (Blueprint $table) {
                $table->foreign('id_producto')->references('id')->on('productos')->onDelete('cascade');
            });
        }
    }
};
