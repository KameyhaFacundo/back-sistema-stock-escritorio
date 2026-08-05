<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('numero_ticket', 50)->nullable()->after('id');
            $table->string('hora', 20)->nullable()->after('fecha');
            $table->string('metodo_pago', 50)->default('efectivo')->after('hora');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['numero_ticket', 'hora', 'metodo_pago']);
        });
    }
};
