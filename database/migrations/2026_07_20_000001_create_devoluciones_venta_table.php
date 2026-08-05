<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devoluciones_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('id_venta')->constrained('ventas')->cascadeOnDelete();
            $table->unsignedBigInteger('id_usuario');
            $table->foreign('id_usuario')->references('nro_usu')->on('users')->cascadeOnDelete();
            $table->string('motivo', 255)->nullable();
            $table->decimal('monto_devuelto', 12, 2);
            // Puede ser menor que monto_devuelto si parte se absorbió bajando
            // saldo pendiente de una venta fiado en vez de salir en efectivo real.
            $table->decimal('monto_efectivo_devuelto', 12, 2)->default(0);
            // false si no había turno abierto para ajustar la caja — igual que
            // el mismo concepto en VentasController::anular().
            $table->boolean('caja_ajustada')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devoluciones_venta');
    }
};
