<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto o PDF del ticket/factura del proveedor — mismo patrón que
     * `imagen_path` en productos (Storage::disk('public'), un solo archivo
     * reemplazable). Se puede adjuntar al cargar la compra o después, desde
     * el detalle — el ticket a veces llega más tarde que la compra en sí.
     */
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->string('comprobante_path')->nullable()->after('cuit');
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn('comprobante_path');
        });
    }
};
