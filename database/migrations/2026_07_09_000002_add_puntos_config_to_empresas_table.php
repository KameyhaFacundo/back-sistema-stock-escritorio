<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->boolean('puntos_activo')->default(false)->after('pos');
            // Cuántos pesos de compra otorgan 1 punto (ej. 100 = "1 punto cada $100").
            $table->decimal('puntos_por_moneda', 10, 2)->nullable()->after('puntos_activo');
            // Cuántos pesos vale 1 punto al canjearlo (ej. 1 = "1 punto = $1 de descuento").
            $table->decimal('puntos_valor_pesos', 10, 2)->nullable()->after('puntos_por_moneda');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['puntos_activo', 'puntos_por_moneda', 'puntos_valor_pesos']);
        });
    }
};
