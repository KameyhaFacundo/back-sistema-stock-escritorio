<?php

use Illuminate\Database\Migrations\Migration;

/**
 * No-op: este fork corre siempre sobre una base nueva (nunca hereda datos de
 * la instalación MySQL original), así que el valor final del enum ya se
 * declaró directo en 2026_06_13_000003_create_movimientos_stock_table.php en
 * vez de ensancharlo acá con un ALTER TABLE MODIFY (sintaxis MySQL-only que
 * además es frágil de replicar vía doctrine/dbal en SQLite para columnas
 * enum). Se deja el archivo para no romper el orden de migraciones ya
 * aplicadas en instalaciones existentes de este fork.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
