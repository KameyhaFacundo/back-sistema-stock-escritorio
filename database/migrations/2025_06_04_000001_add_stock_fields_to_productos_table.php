<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('codigo', 100)->nullable()->unique()->after('producto');
            $table->decimal('costo', 12, 2)->default(0)->after('precio');
            $table->integer('stock')->default(0)->after('costo');
            $table->integer('stock_minimo')->default(5)->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['codigo', 'costo', 'stock', 'stock_minimo']);
        });
    }
};
