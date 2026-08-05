<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_precios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->unsignedBigInteger('id_producto');
            $table->decimal('precio_anterior', 12, 2);
            $table->decimal('precio_nuevo', 12, 2);
            $table->unsignedBigInteger('id_usuario')->nullable();
            $table->timestamps();

            $table->foreign('id_producto')->references('id')->on('productos')->onDelete('cascade');
            $table->index(['id_producto', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_precios');
    }
};
