<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte para facturas en estado "pendiente" (ver EmitirFacturaJob): se
 * crean antes de que ARCA responda, así que todavía no tienen CAE ni
 * número real (el número fiscal lo asigna ARCA vía
 * ArcaService::consultarUltimoComprobante(), no la base local — no hay
 * forma de saberlo de antemano si ARCA no respondió todavía).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->string('cae', 14)->nullable()->change();
            $table->string('vencimiento_cae', 8)->nullable()->change();
            $table->integer('numero')->nullable()->change();
            $table->string('error_mensaje')->nullable()->after('estado');
            // Los ítems de la venta (precio/cantidad por línea) no se
            // persisten en ningún otro lado del lado de la factura — hoy se
            // arman al vuelo y se le pasan directo a ArcaService dentro del
            // mismo request. Una factura "pendiente" los necesita guardados
            // para que el Job pueda terminar de emitirla más tarde, en otro
            // request/proceso.
            $table->json('items')->nullable()->after('error_mensaje');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn('items');
            $table->dropColumn('error_mensaje');
            $table->integer('numero')->nullable(false)->change();
            $table->string('vencimiento_cae', 8)->nullable(false)->change();
            $table->string('cae', 14)->nullable(false)->change();
        });
    }
};
