<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupos_talles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // Una empresa puede tener varias curvas conviviendo a la vez (ej. "Ropa"
            // con S/M/L/XL y "Calzado" con numeración 35-45) — por eso un grupo por
            // rubro/curva en vez de una sola lista de talles fija por empresa.
            $table->string('nombre', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupos_talles');
    }
};
