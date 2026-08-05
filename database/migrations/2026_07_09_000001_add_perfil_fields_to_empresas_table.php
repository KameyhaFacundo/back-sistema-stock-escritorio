<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('cuit')->nullable()->after('nombre');
            $table->string('pais')->nullable()->after('tipo');
            $table->string('direccion')->nullable()->after('pais');
            $table->string('condicion_fiscal')->nullable()->after('direccion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['cuit', 'pais', 'direccion', 'condicion_fiscal']);
        });
    }
};
