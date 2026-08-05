<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('id_grupo_talle')->constrained('grupos_talles')->cascadeOnDelete();
            $table->string('valor', 20);
            // S/M/L/XL o una numeración de calzado no ordenan bien alfabéticamente —
            // "orden" es lo que decide cómo se muestran/generan las variantes.
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talles');
    }
};
