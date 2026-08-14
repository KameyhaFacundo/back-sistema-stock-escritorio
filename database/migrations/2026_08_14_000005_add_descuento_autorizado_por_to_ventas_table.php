<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuando un cajero sin 'aplicar-descuento-ventas' aplica un descuento
     * con la contraseña de alguien que sí tiene el permiso (ver
     * VentasController::autorizarDescuento), queda registrado acá quién lo
     * autorizó — mismo criterio de auditoría que motivo_descuento (columna
     * de al lado), null en el caso normal (cajero con permiso propio).
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('descuento_autorizado_por', 255)->nullable()->after('motivo_descuento');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('descuento_autorizado_por');
        });
    }
};
