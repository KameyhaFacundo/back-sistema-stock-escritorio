<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Control de auditoría: cada vez que alguien usa "Vaciar carrito" en el
     * POS, se guarda una foto de qué había cargado (ítems + total) y quién
     * lo hizo — para poder revisar después si hay un patrón sospechoso
     * (cargar productos y vaciar el carrito en vez de cobrarlos).
     */
    public function up(): void
    {
        Schema::create('carritos_vaciados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('id_sucursal')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('id_usuario')->nullable()->constrained('users', 'nro_usu')->nullOnDelete();
            $table->json('items');
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carritos_vaciados');
    }
};
