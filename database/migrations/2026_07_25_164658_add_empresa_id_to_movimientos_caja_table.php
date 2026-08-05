<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MovimientoCaja no tenía empresa_id ni HasTenant — hoy es seguro solo
     * porque siempre se llega a él a través de un Turno ya scopeado (que sí
     * tiene empresa_id), pero un futuro endpoint que lo busque por su propio
     * id quedaría sin protección. Se agrega la columna, se rellena desde el
     * turno padre, y el modelo pasa a usar HasTenant (mismo patrón que el
     * resto de las tablas, ver 2026_06_13_000002_add_empresa_id_to_tenant_tables).
     */
    public function up(): void
    {
        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->unsignedBigInteger('empresa_id')->nullable()->after('id_turno');
            $table->foreign('empresa_id')->references('id')->on('empresas')->nullOnDelete();
        });

        DB::statement('
            UPDATE movimientos_caja
            INNER JOIN turnos ON turnos.id = movimientos_caja.id_turno
            SET movimientos_caja.empresa_id = turnos.empresa_id
        ');
    }

    public function down(): void
    {
        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->dropForeign(['empresa_id']);
            $table->dropColumn('empresa_id');
        });
    }
};
