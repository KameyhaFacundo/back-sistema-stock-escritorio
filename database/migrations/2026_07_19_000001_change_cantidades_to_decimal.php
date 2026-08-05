<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Primer paso (de 3) para poder vender productos por peso/longitud —
 * hoy toda cantidad es entera, lo que hace imposible registrar "0.5 kg"
 * o "2.5 m". Este cambio es solo de esquema: ningún FormRequest permite
 * todavía mandar un valor fraccionario, así que ningún tenant puede
 * generar un dato decimal a partir de este deploy — el objetivo es
 * verificar el ALTER TABLE de forma aislada antes de tocar la lógica de
 * negocio (StockService, combos, validaciones) en un paso siguiente.
 *
 * Se usa DB::statement() en vez de Schema::table()->change() porque
 * este proyecto no tiene doctrine/dbal instalado (requerido por el
 * método fluido ->change() de Laravel) y no vale la pena sumar una
 * dependencia nueva solo para 6 ALTER TABLE puntuales.
 *
 * decimal(12,2) replica la precisión que ya usa `lotes.cantidad` desde
 * antes — no se inventa un formato nuevo.
 *
 * down(): revierte a integer. Solo es seguro ejecutarlo ANTES de que
 * exista algún dato fraccionario real (es decir, antes de que las fases
 * 2 y 3 de esta función lleguen a producción) — una vez que una
 * ferretería real venda "0.5 kg", este rollback truncaría esos datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE lineas_ventas MODIFY cantidad DECIMAL(12,2) NOT NULL');
        DB::statement('ALTER TABLE lineas_compras MODIFY cantidad DECIMAL(12,2) NOT NULL');
        DB::statement('ALTER TABLE movimientos_stock MODIFY cantidad DECIMAL(12,2) NOT NULL');
        DB::statement('ALTER TABLE combo_componentes MODIFY cantidad DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 1.00');
        DB::statement('ALTER TABLE producto_stock MODIFY stock DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        DB::statement('ALTER TABLE producto_stock MODIFY stock_minimo DECIMAL(12,2) NOT NULL DEFAULT 5.00');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE lineas_ventas MODIFY cantidad INT NOT NULL');
        DB::statement('ALTER TABLE lineas_compras MODIFY cantidad INT NOT NULL');
        DB::statement('ALTER TABLE movimientos_stock MODIFY cantidad INT NOT NULL');
        DB::statement('ALTER TABLE combo_componentes MODIFY cantidad INT UNSIGNED NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE producto_stock MODIFY stock INT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE producto_stock MODIFY stock_minimo INT NOT NULL DEFAULT 5');
    }
};
