<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->enum('estado_pago', ['pagado', 'parcial', 'pendiente'])->default('pagado')->after('metodo_pago');
            $table->decimal('monto_cobrado', 12, 2)->default(0)->after('estado_pago');
        });

        Schema::create('pagos_cliente', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_venta');
            $table->unsignedBigInteger('id_usuario');
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->string('metodo_pago', 50)->default('efectivo');
            $table->string('nota', 255)->nullable();
            $table->timestamps();

            $table->foreign('id_venta')->references('id')->on('ventas')->onDelete('cascade');
            $table->foreign('id_usuario')->references('nro_usu')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_cliente');
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['estado_pago', 'monto_cobrado']);
        });
    }
};
