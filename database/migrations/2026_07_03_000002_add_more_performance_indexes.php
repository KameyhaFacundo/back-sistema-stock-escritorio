<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->index('empresa_id', 'idx_categorias_empresa');
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->index(['empresa_id', 'id_categoria'], 'idx_productos_empresa_categoria');
            $table->index(['empresa_id', 'id_proveedor'], 'idx_productos_empresa_proveedor');
        });

        Schema::table('turnos', function (Blueprint $table) {
            $table->index(['empresa_id', 'id_usuario', 'estado'], 'idx_turnos_empresa_usuario_estado');
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->index(['empresa_id', 'id_turno'], 'idx_ventas_empresa_turno');
        });

        Schema::table('movimientos_stock', function (Blueprint $table) {
            $table->index(['empresa_id', 'fecha', 'tipo'], 'idx_movimientos_empresa_fecha_tipo');
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->index(['empresa_id', 'fecha'], 'idx_facturas_empresa_fecha');
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropIndex('idx_categorias_empresa');
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->dropIndex('idx_productos_empresa_categoria');
            $table->dropIndex('idx_productos_empresa_proveedor');
        });

        Schema::table('turnos', function (Blueprint $table) {
            $table->dropIndex('idx_turnos_empresa_usuario_estado');
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex('idx_ventas_empresa_turno');
        });

        Schema::table('movimientos_stock', function (Blueprint $table) {
            $table->dropIndex('idx_movimientos_empresa_fecha_tipo');
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->dropIndex('idx_facturas_empresa_fecha');
        });
    }
};
