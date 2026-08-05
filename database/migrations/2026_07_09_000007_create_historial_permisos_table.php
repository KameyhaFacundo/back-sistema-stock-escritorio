<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_permisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // A quién le cambiaron los permisos y quién lo hizo — casi siempre son
            // usuarios distintos (un admin editando a otro usuario).
            $table->unsignedBigInteger('id_usuario_afectado');
            $table->unsignedBigInteger('id_usuario_actor')->nullable();
            $table->json('permisos_agregados')->nullable();
            $table->json('permisos_quitados')->nullable();
            $table->timestamps();

            $table->foreign('id_usuario_afectado')->references('nro_usu')->on('users')->cascadeOnDelete();
            $table->foreign('id_usuario_actor')->references('nro_usu')->on('users')->nullOnDelete();
            $table->index(['id_usuario_afectado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_permisos');
    }
};
