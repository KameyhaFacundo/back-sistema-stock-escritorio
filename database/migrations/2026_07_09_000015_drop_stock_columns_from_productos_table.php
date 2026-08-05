<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El stock pasa a vivir en producto_stock (por sucursal) — ver migración
     * backfill_sucursales_y_stock, que corre antes que esta y ya copió todos los
     * valores. Estas columnas quedarían huérfanas y podrían desincronizarse si se
     * mantuvieran en paralelo.
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['stock', 'stock_minimo']);
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->integer('stock')->default(0)->after('costo');
            $table->integer('stock_minimo')->default(5)->after('stock');
        });
    }
};
