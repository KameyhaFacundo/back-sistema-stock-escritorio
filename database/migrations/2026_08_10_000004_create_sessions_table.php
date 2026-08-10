<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SESSION_DRIVER pasa de "file" a "database" — mismo motivo que la
// migración de cache.php de al lado (flock() entre procesos separados
// pudiendo colgarse y tumbar el servidor entero). Sin FK a users: la PK real
// de esa tabla es nro_usu, no id, y el session handler de Laravel no
// necesita la constraint para funcionar, solo la columna.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
