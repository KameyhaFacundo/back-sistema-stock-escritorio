<?php

namespace Database\Seeders;

use App\Models\TipoUsuario;
use Illuminate\Database\Seeder;

class TipoUsuarioSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            [
                'codigo' => 'ADMIN',
                'detalle' => 'Usuario administrador del sistema',
            ],
            [
                'codigo' => 'USER',
                'detalle' => 'Usuario estándar del sistema',
            ],
        ];

        foreach ($tipos as $tipo) {
            TipoUsuario::firstOrCreate(
                ['codigo' => $tipo['codigo']],
                $tipo
            );
        }
    }
}
