<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 150);
            $table->string('direccion', 255)->nullable();
            $table->string('telefono', 50)->nullable();
            $table->boolean('activo')->default(true);
            // La sucursal principal es la que se crea automáticamente al migrar una
            // empresa existente a multi-sucursal (ver backfill) — sirve de destino por
            // defecto y no se puede eliminar desde el front.
            $table->boolean('es_principal')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sucursales');
    }
};
