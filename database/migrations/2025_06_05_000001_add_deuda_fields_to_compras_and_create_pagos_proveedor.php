<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->enum('estado_deuda', ['pagado', 'parcial', 'pendiente'])->default('pagado')->after('metodo_pago');
            $table->decimal('monto_pagado', 12, 2)->default(0)->after('estado_deuda');
        });

        Schema::create('pagos_proveedor', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_compra');
            $table->unsignedBigInteger('id_usuario');
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->string('metodo_pago', 50)->default('efectivo');
            $table->string('nota', 255)->nullable();
            $table->timestamps();

            $table->foreign('id_compra')->references('id')->on('compras')->onDelete('cascade');
            $table->foreign('id_usuario')->references('nro_usu')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_proveedor');
        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn(['estado_deuda', 'monto_pagado']);
        });
    }
};
