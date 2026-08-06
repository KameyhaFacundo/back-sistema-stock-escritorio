<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La validación (CreateProveedorRequest) ya trataba el CUIT como opcional
     * (nullable + normaliza '' a null), pero la columna en sí seguía siendo
     * NOT NULL — crear un proveedor sin CUIT rompía con un error 500 de
     * SQLite en vez del mensaje de validación esperado. Mismo fix que ya se
     * había hecho para clientes (ver make_cuit_nullable_in_clientes_table).
     */
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('cuit', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('cuit', 20)->nullable(false)->change();
        });
    }
};
