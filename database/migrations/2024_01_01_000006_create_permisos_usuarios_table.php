<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permisos_usuarios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_permiso');
            $table->unsignedBigInteger('id_usuario');

            $table->foreign('id_permiso')->references('id')->on('permisos')->onDelete('cascade');
            $table->foreign('id_usuario')->references('nro_usu')->on('users')->onDelete('cascade');

            $table->unique(['id_permiso', 'id_usuario']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos_usuarios');
    }
};
