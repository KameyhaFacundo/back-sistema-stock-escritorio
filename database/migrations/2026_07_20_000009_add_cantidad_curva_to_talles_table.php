<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuántas unidades de este talle vienen en UNA curva/bulto al comprarle
     * al proveedor (ej. una curva de 12 remeras: 1 S, 2 M, 3 L, 3 XL, 2 XXL,
     * 1 XXXL) — nullable porque no todos los talles se compran así, y un
     * talle sin esto configurado no cambia en nada su comportamiento actual.
     */
    public function up(): void
    {
        Schema::table('talles', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_curva')->nullable()->after('orden');
        });
    }

    public function down(): void
    {
        Schema::table('talles', function (Blueprint $table) {
            $table->dropColumn('cantidad_curva');
        });
    }
};
