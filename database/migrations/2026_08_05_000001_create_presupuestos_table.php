<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Un presupuesto es su propio modelo, no una Venta a medias — a diferencia
// de Compra, VentaCreacionService::crear() no gatea el descuento de stock/caja
// por estado, así que "estado: pendiente" no sirve como borrador ahí (ver
// plan de esta fase). id_venta queda null hasta que se convierte.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presupuestos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('id_sucursal')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->unsignedBigInteger('id_cliente')->nullable();
            $table->foreign('id_cliente')->references('id')->on('clientes')->nullOnDelete();
            $table->unsignedBigInteger('id_usuario');
            $table->foreign('id_usuario')->references('nro_usu')->on('users')->cascadeOnDelete();
            $table->enum('estado', ['vigente', 'convertido', 'vencido', 'cancelado'])->default('vigente');
            $table->date('fecha');
            $table->decimal('monto_total', 12, 2)->default(0);
            $table->text('notas')->nullable();
            $table->foreignId('id_venta')->nullable()->constrained('ventas')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presupuestos');
    }
};
