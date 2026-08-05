<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una Nota de Crédito es, para ARCA, "otro comprobante" — mismo llamado
     * WSFE que una factura, solo cambia el tipo (3/8/13 en vez de 1/6/11) y
     * que lleva un comprobante asociado. No hace falta una tabla nueva: se
     * reusa `facturas`, agregando el vínculo hacia el comprobante que credita.
     */
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('id_comprobante_asociado')->nullable()->after('id_venta')
                ->constrained('facturas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_comprobante_asociado');
        });
    }
};
