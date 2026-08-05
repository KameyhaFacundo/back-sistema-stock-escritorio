<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Un combo mezcla productos de categorías distintas — no tiene sentido
     * obligarlo a elegir una sola. Se usa SQL crudo (no ->change()) para no
     * depender de doctrine/dbal, que no está instalado en este proyecto.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE productos MODIFY id_categoria BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE productos MODIFY id_categoria BIGINT UNSIGNED NOT NULL');
    }
};
