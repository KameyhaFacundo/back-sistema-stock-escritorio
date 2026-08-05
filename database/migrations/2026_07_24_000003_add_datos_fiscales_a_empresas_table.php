<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos fiscales que faltaban para el ticket impreso (Configuración →
     * Datos del negocio) — hoy quedan en blanco al imprimir un comprobante
     * porque no había dónde cargarlos.
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('iibb', 50)->nullable()->after('condicion_fiscal');
            $table->date('inicio_actividad')->nullable()->after('iibb');
            $table->string('email', 255)->nullable()->after('inicio_actividad');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['iibb', 'inicio_actividad', 'email']);
        });
    }
};
