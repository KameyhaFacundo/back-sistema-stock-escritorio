<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id');
            $table->unsignedBigInteger('id_venta')->nullable();
            $table->unsignedBigInteger('id_usuario');
            $table->integer('tipo_comprobante'); // 1=FA, 6=FB, 11=FC
            $table->integer('punto_venta');
            $table->integer('numero');
            $table->string('cae', 14);
            $table->string('vencimiento_cae', 8);
            $table->string('fecha', 8);
            $table->decimal('total', 12, 2);
            $table->decimal('neto', 12, 2)->default(0);
            $table->decimal('iva', 12, 2)->default(0);
            $table->integer('tipo_documento')->default(99);
            $table->string('numero_documento')->default('0');
            $table->string('cliente_nombre')->nullable();
            $table->string('estado')->default('emitida');
            $table->timestamps();

            $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
            $table->foreign('id_venta')->references('id')->on('ventas')->onDelete('set null');
            $table->foreign('id_usuario')->references('nro_usu')->on('users')->onDelete('cascade');

            $table->index(['empresa_id', 'punto_venta', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas');
    }
};
