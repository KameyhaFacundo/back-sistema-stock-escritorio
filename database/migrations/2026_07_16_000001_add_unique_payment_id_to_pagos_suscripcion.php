<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cierre a nivel de base de datos del fix de idempotencia del webhook de
     * suscripción (SubscripcionController::webhook): el chequeo a nivel de
     * aplicación evita duplicados en el caso normal (reintentos secuenciales
     * de MP), pero un índice único es lo único que garantiza que dos
     * requests concurrentes con el mismo payment_id no puedan colarse las dos.
     * MySQL permite múltiples NULL en un índice único, así que no afecta las
     * filas viejas sin payment_id.
     */
    public function up(): void
    {
        Schema::table('pagos_suscripcion', function (Blueprint $table) {
            $table->unique('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('pagos_suscripcion', function (Blueprint $table) {
            $table->dropUnique(['payment_id']);
        });
    }
};
