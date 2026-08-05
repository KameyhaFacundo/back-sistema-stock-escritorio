<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->decimal('precio_original', 12, 2)->nullable()->after('precio_venta');
        });

        // Rellenar precio_original con el valor actual de precio_venta para filas existentes
        DB::statement('UPDATE lineas_ventas SET precio_original = precio_venta WHERE precio_original IS NULL');
    }

    public function down(): void
    {
        Schema::table('lineas_ventas', function (Blueprint $table) {
            $table->dropColumn('precio_original');
        });
    }
};
