<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cómo se resolvió con el cliente la diferencia a favor de una devolución
     * (ver ModalDevolucionVenta en Dashboard.jsx) — antes se asumía siempre
     * "efectivo del cajón" sin importar cómo se había pagado la venta
     * original, lo cual descuadraba el arqueo en devoluciones de ventas
     * pagadas con tarjeta/transferencia/QR (esa plata nunca estuvo físicamente
     * en la caja). Solo 'efectivo' ajusta caja — ver DevolucionVentaService.
     */
    public function up(): void
    {
        Schema::table('devoluciones_venta', function (Blueprint $table) {
            $table->string('forma_reintegro', 20)->nullable()->after('monto_efectivo_devuelto');
        });
    }

    public function down(): void
    {
        Schema::table('devoluciones_venta', function (Blueprint $table) {
            $table->dropColumn('forma_reintegro');
        });
    }
};
