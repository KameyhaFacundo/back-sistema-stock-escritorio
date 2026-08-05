<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Condición frente al IVA del cliente (Monotributista/Responsable
     * Inscripto/Exento/Consumidor Final) — sin esto el ticket impreso
     * siempre decía "CONSUMIDOR FINAL" para cualquier cliente con nombre
     * cargado. Mismas opciones que ya usa condicion_fiscal de la empresa
     * (TabNegocio en ConfigModal.jsx).
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('condicion_iva', 50)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('condicion_iva');
        });
    }
};
