<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La FK original (create_facturas_table) es id_venta -> ventas.id
     * ON DELETE SET NULL — MySQL rechaza volver esa columna NOT NULL
     * mientras esa FK siga apuntando a "SET NULL" (error 1830: no puede
     * poner NULL en una columna que ya no lo admite si la venta referenciada
     * se borra). Hay que sacar la FK vieja antes de cambiar la columna, y
     * volver a crearla con un onDelete que tenga sentido ahora que id_venta
     * nunca puede ser null: RESTRICT (default de Laravel al omitirlo) — una
     * factura documenta esa venta puntual, no tiene sentido que pueda
     * quedar sin ella, así que no se puede borrar una venta que ya tiene
     * factura.
     *
     * SQLite no tiene este problema (->change() reconstruye la tabla entera
     * por debajo sin chocar con la FK) PERO tampoco soporta dropForeign() en
     * absoluto — sin esta rama, el mismo fix que arregla MySQL rompe SQLite
     * (donde se probó todo este build, por eso el bug original de MySQL
     * nunca se vio acá).
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('facturas', function (Blueprint $table) {
                $table->unsignedBigInteger('id_venta')->nullable(false)->change();
            });
            return;
        }

        Schema::table('facturas', function (Blueprint $table) {
            $table->dropForeign('facturas_id_venta_foreign');
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->unsignedBigInteger('id_venta')->nullable(false)->change();
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->foreign('id_venta')->references('id')->on('ventas')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('facturas', function (Blueprint $table) {
                $table->unsignedBigInteger('id_venta')->nullable()->change();
            });
            return;
        }

        Schema::table('facturas', function (Blueprint $table) {
            $table->dropForeign(['id_venta']);
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->unsignedBigInteger('id_venta')->nullable()->change();
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->foreign('id_venta')->references('id')->on('ventas')->onDelete('set null');
        });
    }
};
