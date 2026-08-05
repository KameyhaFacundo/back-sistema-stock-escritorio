<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedBigInteger('id_proveedor')->nullable()->after('id_categoria');
            $table->foreign('id_proveedor')->references('id')->on('proveedores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropForeign(['id_proveedor']);
            $table->dropColumn('id_proveedor');
        });
    }
};
