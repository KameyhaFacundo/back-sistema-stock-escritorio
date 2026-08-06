<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Efecto secundario de hacer proveedores.cuit opcional (ver
     * make_cuit_nullable_in_proveedores_table): ComprasController::store()
     * copia $proveedor->cuit a la compra (columna denormalizada, para tener
     * el CUIT "congelado" al momento de la compra) — con un proveedor sin
     * CUIT, ese valor es null y compras.cuit todavía era NOT NULL, así que
     * la compra explotaba con un 500 genérico ("Error al crear la compra").
     */
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->string('cuit', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->string('cuit', 20)->nullable(false)->change();
        });
    }
};
