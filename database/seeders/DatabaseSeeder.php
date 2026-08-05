<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // DatosRealesSeeder y PagosDemoSeeder quedan afuera del seeding de una
        // instalación local nueva: son utilitarios de datos de demostración
        // multi-tenant (reset de ventas de prueba, historial de pagos de
        // suscripción falso) que no aplican a una ferretería real con su
        // propia base de datos. Los archivos quedan en el repo sin usarse.
        $this->call([
            TipoUsuarioSeeder::class,
            PermisoSeeder::class,
            RolSeeder::class,
            UsuarioSeeder::class,
        ]);
    }
}
