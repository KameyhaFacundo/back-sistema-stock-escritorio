<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combo_componentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // El combo también es un producto (con su propio precio y código de
            // barras) — solo que en vez de tener stock propio, consume el de sus
            // componentes al venderse. Ver VentaCreacionService::crear().
            $table->foreignId('id_combo')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('id_producto')->constrained('productos')->cascadeOnDelete();
            $table->unsignedInteger('cantidad')->default(1);
            $table->timestamps();

            $table->unique(['id_combo', 'id_producto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combo_componentes');
    }
};
