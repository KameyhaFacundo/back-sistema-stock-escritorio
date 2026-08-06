<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mismo shape que lineas_ventas a propósito — convertir un presupuesto en
// venta es un mapeo directo línea por línea (ver PresupuestosController::convertir()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lineas_presupuesto', function (Blueprint $table) {
            $table->id('id_linea');
            $table->foreignId('id_presupuesto')->constrained('presupuestos')->cascadeOnDelete();
            $table->unsignedBigInteger('id_producto')->nullable();
            $table->foreign('id_producto')->references('id')->on('productos')->nullOnDelete();
            $table->string('nombre')->nullable();
            $table->decimal('precio_venta', 12, 2);
            $table->decimal('cantidad', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lineas_presupuesto');
    }
};
