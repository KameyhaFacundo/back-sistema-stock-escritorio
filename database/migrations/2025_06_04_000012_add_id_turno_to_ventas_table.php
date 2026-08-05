<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->unsignedBigInteger('id_turno')->nullable()->after('id');
            $table->foreign('id_turno')->references('id')->on('turnos')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropForeign(['id_turno']);
            $table->dropColumn('id_turno');
        });
    }
};
