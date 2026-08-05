<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->unsignedBigInteger('id_usuario_anulacion')->nullable()->after('id_usuario');
            $table->timestamp('fecha_anulacion')->nullable()->after('id_usuario_anulacion');

            $table->foreign('id_usuario_anulacion')
                  ->references('nro_usu')
                  ->on('users')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropForeign(['id_usuario_anulacion']);
            $table->dropColumn(['id_usuario_anulacion', 'fecha_anulacion']);
        });
    }
};
