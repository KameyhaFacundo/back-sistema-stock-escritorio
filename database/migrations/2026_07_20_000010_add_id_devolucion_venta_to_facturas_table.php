<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo opcional entre una Nota de Crédito y la devolución que la
     * originó — para poder mostrar en Facturas el detalle de esa devolución
     * (fecha, monto, motivo) sin tener que adivinarlo por monto/fecha.
     */
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('id_devolucion_venta')->nullable()->after('id_comprobante_asociado')
                ->constrained('devoluciones_venta')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_devolucion_venta');
        });
    }
};
