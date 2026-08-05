<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\TipoUsuario;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsuarioSeeder extends Seeder
{
    public function run(): void
    {
        $tipoAdmin = TipoUsuario::where('codigo', 'ADMIN')->first();
        $rolAdmin  = Rol::where('codigo', 'admin')->first();

        $empresa = Empresa::firstOrCreate(
            ['nombre' => 'Stock Manager'],
            [
                'tipo'          => 'otros',
                'plan'          => 'gratis',
                'trial_ends_at' => now()->addDays(30),
                'suspendida'    => false,
            ]
        );

        User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'des_usu'         => 'Administrador',
                'password'        => Hash::make('admin123'),
                'id_tipo_usuario' => $tipoAdmin?->id,
                'id_rol'          => $rolAdmin?->id,
                'empresa_id'      => $empresa->id,
            ]
        );
    }
}
