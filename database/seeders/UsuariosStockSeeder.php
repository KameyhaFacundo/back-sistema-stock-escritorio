<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsuariosStockSeeder extends Seeder
{
    public function run(): void
    {
        // Obtener roles
        $rolAdmin = Rol::where('codigo', 'admin')->first();

        // Usuario vendedor
        User::firstOrCreate(
            ['email' => 'vendedor@stock.com'],
            [
                'des_usu' => 'Usuario Vendedor',
                'password' => Hash::make('vendedor123'),
                'id_rol' => $rolAdmin?->id,
            ]
        );

        // Usuario comprador
        User::firstOrCreate(
            ['email' => 'comprador@stock.com'],
            [
                'des_usu' => 'Usuario Comprador',
                'password' => Hash::make('comprador123'),
                'id_rol' => $rolAdmin?->id,
            ]
        );

        // Usuario gestor de stock
        User::firstOrCreate(
            ['email' => 'gestor@stock.com'],
            [
                'des_usu' => 'Gestor de Stock',
                'password' => Hash::make('gestor123'),
                'id_rol' => $rolAdmin?->id,
            ]
        );
    }
}
