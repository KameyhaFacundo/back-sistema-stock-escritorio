<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// El logo se manejaba con localStorage['empresa_logo'] en el navegador — una
// key global compartida por TODAS las empresas que se vean desde ese
// navegador (ej. el SuperAdmin configurando distintos clientes), así que
// cambiarlo para una empresa lo cambiaba para todas. Pasa a ser un dato real
// de la empresa en el backend.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
