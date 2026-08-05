<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tablas que necesitan aislamiento por empresa (pk → nombre de la columna PK)
    private array $tables = [
        'users'       => 'nro_usu',   // PK distinto
        'categorias'  => 'id',
        'productos'   => 'id',
        'proveedores' => 'id',
        'clientes'    => 'id',
        'compras'     => 'id',
        'ventas'      => 'id',
        'turnos'      => 'id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $pk) {
            Schema::table($table, function (Blueprint $t) use ($pk) {
                $t->unsignedBigInteger('empresa_id')->nullable()->after($pk);
                $t->foreign('empresa_id')->references('id')->on('empresas')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['empresa_id']);
                $t->dropColumn('empresa_id');
            });
        }
    }
};
