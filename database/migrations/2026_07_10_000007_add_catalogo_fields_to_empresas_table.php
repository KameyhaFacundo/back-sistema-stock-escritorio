<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('catalogo_slug')->nullable()->unique()->after('logo_path');
            $table->boolean('catalogo_activo')->default(false)->after('catalogo_slug');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['catalogo_slug', 'catalogo_activo']);
        });
    }
};
