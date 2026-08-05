<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TipoUsuarioSeeder::class,
            PermisoSeeder::class,
            RolSeeder::class,
            UsuarioSeeder::class,
            DatosRealesSeeder::class,
            PagosDemoSeeder::class,
        ]);
    }
}
