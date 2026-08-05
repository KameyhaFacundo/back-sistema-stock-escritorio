<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('id_tipo_usuario')->nullable()->after('admin');
            $table->unsignedBigInteger('id_rol')->nullable()->after('id_tipo_usuario');
            $table->boolean('is_admin')->default(false)->after('id_rol');
            $table->softDeletes();

            $table->foreign('id_tipo_usuario')->references('id')->on('tipo_usuarios')->onDelete('set null');
            $table->foreign('id_rol')->references('id')->on('roles')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['id_tipo_usuario']);
            $table->dropForeign(['id_rol']);
            $table->dropColumn(['id_tipo_usuario', 'id_rol', 'is_admin', 'deleted_at']);
        });
    }
};
