<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_point_intentos', function (Blueprint $table) {
            $table->enum('tipo', ['point', 'qr'])->default('point')->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('venta_point_intentos', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
