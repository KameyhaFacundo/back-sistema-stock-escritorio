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
            // Lista de planes ("esencial","pro","ia") por los que esta empresa ya
            // recibió el bono único de facturas gratis al activarlos — evita que
            // bajar y volver a subir de plan regale el bono de nuevo (ver
            // Empresa::otorgarBonoFacturas()).
            $table->json('facturas_bono_otorgado')->nullable()->after('facturas_disponibles');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('facturas_bono_otorgado');
        });
    }
};
