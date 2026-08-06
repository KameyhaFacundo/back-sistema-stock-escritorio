<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separa el código de barras del código/SKU manual — hasta ahora
     * `codigo` cumplía los dos roles a la vez (referencia manual Y valor
     * dibujado en el barcode de la etiqueta). El backfill copia el valor
     * actual de `codigo` para que ningún producto existente pierda su
     * código de barras impreso el día que esto se despliegue.
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('codigo_barras', 100)->nullable()->unique()->after('codigo');
        });

        DB::table('productos')
            ->whereNull('codigo_barras')
            ->whereNotNull('codigo')
            ->update(['codigo_barras' => DB::raw('codigo')]);
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('codigo_barras');
        });
    }
};
