<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Antes, anular una venta con fiado ya cobrado BORRABA en serio la fila
     * de pagos_cliente (VentasController::anular) — la única prueba de que
     * alguien había registrado ese cobro desaparecía sin dejar rastro de
     * quién lo anuló ni cuándo. Ahora se marca en vez de borrarse: si un
     * empleado carga un cobro que nunca pasó y después lo tapa anulando la
     * venta entera, el registro sigue existiendo (visible para el dueño),
     * solo que marcado como anulado.
     */
    public function up(): void
    {
        Schema::table('pagos_cliente', function (Blueprint $table) {
            $table->boolean('anulado')->default(false)->after('nota');
            $table->unsignedBigInteger('id_usuario_anulacion')->nullable()->after('anulado');
            $table->timestamp('fecha_anulacion')->nullable()->after('id_usuario_anulacion');

            $table->foreign('id_usuario_anulacion')->references('nro_usu')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pagos_cliente', function (Blueprint $table) {
            $table->dropForeign(['id_usuario_anulacion']);
            $table->dropColumn(['anulado', 'id_usuario_anulacion', 'fecha_anulacion']);
        });
    }
};
