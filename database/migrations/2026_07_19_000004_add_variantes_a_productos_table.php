<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Primer paso (de 3) para vender productos con variantes por talle — solo
 * para indumentaria. Puramente de esquema: nada todavía puede setear estas
 * columnas desde ningún endpoint, así que ningún tenant se ve afectado.
 *
 * id_producto_padre es autorreferencial (un producto "variante" apunta a su
 * producto "madre") — no existía este patrón antes en este proyecto, pero es
 * la relación correcta: es 1 a 1 (una variante, un padre), no muchos a
 * muchos como combo_componentes, así que una columna directa alcanza. Se
 * deja en nullOnDelete (no cascade) para que un borrado físico nunca arrastre
 * variantes por fuera de la lógica explícita de la app (ver
 * ProductosController::destroy()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->foreignId('id_producto_padre')->nullable()->after('id')
                ->constrained('productos')->nullOnDelete();
            // Solo lo usa el producto madre (id_producto_padre null) — cuál curva
            // de talles eligió, para saber qué variantes generar/propagar.
            $table->foreignId('id_grupo_talle')->nullable()->after('id_producto_padre')
                ->constrained('grupos_talles')->nullOnDelete();
            // Solo lo usa una variante (id_producto_padre no null) — cuál talle
            // puntual es esta fila dentro de la curva del padre.
            $table->foreignId('id_talle')->nullable()->after('id_grupo_talle')
                ->constrained('talles')->nullOnDelete();
            $table->boolean('tiene_variantes')->default(false)->after('es_combo');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_producto_padre');
            $table->dropConstrainedForeignId('id_grupo_talle');
            $table->dropConstrainedForeignId('id_talle');
            $table->dropColumn('tiene_variantes');
        });
    }
};
