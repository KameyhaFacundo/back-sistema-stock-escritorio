<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            // Certificado y clave privada de ARCA (WSAA/WSFE), propios de cada
            // empresa — encriptados por el cast 'encrypted' en el modelo, no
            // se guardan como archivos en disco (ver Empresa::$casts).
            $table->text('arca_cert')->nullable()->after('arca');
            $table->text('arca_key')->nullable()->after('arca_cert');
            $table->string('arca_passphrase')->nullable()->after('arca_key');
            $table->integer('arca_punto_venta')->nullable()->after('arca_passphrase');
            $table->boolean('arca_homologacion')->default(true)->after('arca_punto_venta');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['arca_cert', 'arca_key', 'arca_passphrase', 'arca_punto_venta', 'arca_homologacion']);
        });
    }
};
