<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->unsignedBigInteger('id_cliente')->nullable()->change();
            $table->string('cuit', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->unsignedBigInteger('id_cliente')->nullable(false)->change();
            $table->string('cuit', 20)->nullable(false)->change();
        });
    }
};
