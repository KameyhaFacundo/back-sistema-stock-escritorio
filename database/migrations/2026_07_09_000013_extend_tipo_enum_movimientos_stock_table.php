<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE movimientos_stock MODIFY tipo ENUM('venta','compra','ajuste','transferencia_salida','transferencia_entrada')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE movimientos_stock MODIFY tipo ENUM('venta','compra','ajuste')");
    }
};
