<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Al crear un usuario ahora se manda un mail de confirmación (mismo token de
// email_token/email_token_created_at ya usado para el cambio de email) —
// email_verified_at queda null hasta que se confirma. Las cuentas que ya
// existían antes de este cambio se marcan verificadas de una (backfill más
// abajo): si no, todas aparecerían como "sin verificar" de golpe, que sería
// una alarma falsa para cuentas viejas y legítimas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email_token_created_at');
        });

        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
