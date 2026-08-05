<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_point_intentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_turno')->nullable();
            $table->decimal('monto', 12, 2);
            $table->string('mp_intento_id')->nullable();
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado', 'cancelado'])->default('pendiente');
            $table->json('venta_payload');
            $table->unsignedBigInteger('id_venta')->nullable();
            $table->timestamps();

            $table->foreign('id_usuario')->references('nro_usu')->on('users')->onDelete('cascade');
            $table->foreign('id_turno')->references('id')->on('turnos')->onDelete('set null');
            $table->foreign('id_venta')->references('id')->on('ventas')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_point_intentos');
    }
};
