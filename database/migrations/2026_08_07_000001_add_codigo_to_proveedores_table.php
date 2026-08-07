<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Código de referencia propio del proveedor (alfanumérico, ej. "AA223" o
     * "111") — lo carga el usuario a mano, sin formato fijo y sin unique: a
     * diferencia del código de Productos, acá se puede repetir entre
     * proveedores (lo único que garantiza unicidad es el id interno).
     */
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('codigo', 100)->nullable()->after('persona');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('codigo');
        });
    }
};
