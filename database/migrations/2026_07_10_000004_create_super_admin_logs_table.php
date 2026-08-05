<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Registro de auditoría de acciones del super admin sobre empresas clientes
// (suspender, extender prueba, cambiar plan, registrar pago, impersonar,
// gestionar usuarios) — hoy esas acciones se ejecutan pero no dejan rastro
// visible en el panel. Denormaliza el email del admin y el nombre de la
// empresa para que el historial siga siendo legible aunque el usuario o la
// empresa se borren después.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('super_admin_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('super_admin_id')->nullable()->constrained('users', 'nro_usu')->nullOnDelete();
            $table->string('super_admin_email');
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->string('empresa_nombre')->nullable();
            $table->string('accion');
            $table->text('detalle')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('super_admin_logs');
    }
};
