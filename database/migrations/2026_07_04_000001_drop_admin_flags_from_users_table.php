<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// El bypass total "is_admin"/"admin" quedaba en paralelo al sistema de rol + permisos
// y generaba confusión (dos formas distintas de ser "administrador"). Se unifica en
// un solo concepto: el rol global "Administrador" ya trae todos los permisos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'admin']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('admin')->default(false)->after('id_rol');
            $table->boolean('is_admin')->default(false)->after('admin');
        });
    }
};
