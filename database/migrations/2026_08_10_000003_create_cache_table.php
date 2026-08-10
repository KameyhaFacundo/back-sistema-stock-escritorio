<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CACHE_DRIVER pasa de "file" a "database" — el driver de archivos usa
// flock() (Filesystem::sharedGet(), fopen+flock en modo lectura compartida)
// para leer cada entrada de forma segura entre procesos. Con el servidor web
// (php artisan serve) y la cola (queue:work) corriendo como procesos PHP
// separados pero pegándole a los mismos archivos de caché, un flock() que
// queda esperando el lock del otro proceso puede colgarse — y a los 30s
// max_execution_time mata TODO el servidor ("El servidor se detuvo",
// visto en vivo probando Stock Prueba, con "Maximum execution time of 30
// seconds exceeded" apuntando justo a ese flock() en el log). La base de
// datos no tiene ese problema: SQLite maneja la concurrencia con sus propios
// tiempos de espera cortos, no con un lock de archivo del sistema operativo
// que puede quedarse colgado indefinidamente.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
