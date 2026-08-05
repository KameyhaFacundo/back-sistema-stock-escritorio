<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('tipo')->nullable();           // tipo de comercio del onboarding
            $table->string('plan')->default('free');      // free | pro
            $table->boolean('arca')->default(false);      // facturación electrónica
            $table->string('pos')->nullable();            // si usaba sistema previo
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
