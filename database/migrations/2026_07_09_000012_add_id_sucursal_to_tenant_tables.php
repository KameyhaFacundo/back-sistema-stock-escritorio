<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tablas que pasan a estar ligadas a una sucursal (pk → nombre de la columna PK)
    private array $tables = [
        'users'             => 'nro_usu',
        'ventas'            => 'id',
        'compras'           => 'id',
        'turnos'            => 'id',
        'movimientos_stock' => 'id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $pk) {
            Schema::table($table, function (Blueprint $t) use ($pk) {
                $t->unsignedBigInteger('id_sucursal')->nullable()->after($pk);
                $t->foreign('id_sucursal')->references('id')->on('sucursales')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['id_sucursal']);
                $t->dropColumn('id_sucursal');
            });
        }
    }
};
