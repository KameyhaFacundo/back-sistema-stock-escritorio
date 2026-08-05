<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_usuario');
            $table->enum('estado', ['abierta', 'cerrada'])->default('abierta');
            $table->date('fecha');
            $table->string('hora_apertura', 10);
            $table->string('hora_cierre', 10)->nullable();
            $table->decimal('monto_inicial', 12, 2)->default(0);
            $table->decimal('monto_final', 12, 2)->nullable();
            $table->decimal('ventas_efectivo', 12, 2)->default(0);
            $table->decimal('efectivo_actual', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('id_usuario')->references('nro_usu')->on('users')->onDelete('cascade');
        });

        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_turno');
            $table->enum('tipo', ['ingreso', 'egreso']);
            $table->decimal('monto', 12, 2);
            $table->string('motivo', 255)->nullable();
            $table->string('hora', 10);
            $table->timestamps();

            $table->foreign('id_turno')->references('id')->on('turnos')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja');
        Schema::dropIfExists('turnos');
    }
};
