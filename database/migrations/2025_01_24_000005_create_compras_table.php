<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_proveedor');
            $table->unsignedBigInteger('id_usuario');
            $table->enum('estado', ['pendiente', 'confirmada', 'cancelada'])->default('pendiente');
            $table->date('fecha');
            $table->decimal('monto_total', 12, 2)->default(0);
            $table->string('cuit', 20);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('id_proveedor')
                  ->references('id')
                  ->on('proveedores')
                  ->onDelete('cascade');

            $table->foreign('id_usuario')
                  ->references('nro_usu')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
