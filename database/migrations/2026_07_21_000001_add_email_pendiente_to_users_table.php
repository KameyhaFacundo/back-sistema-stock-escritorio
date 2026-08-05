<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cambiar el email desde "Mi perfil" ya no lo aplica directo — queda acá
// pendiente hasta que se confirma desde el link mandado a la dirección
// nueva (mismo criterio de seguridad que "olvidé mi contraseña", ver
// AuthController::forgotPassword/resetPassword). email_token guarda el hash
// del token igual que password_reset_tokens.token, no el token en texto plano.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_pendiente')->nullable()->after('email');
            $table->string('email_token')->nullable()->after('email_pendiente');
            $table->timestamp('email_token_created_at')->nullable()->after('email_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_pendiente', 'email_token', 'email_token_created_at']);
        });
    }
};
