<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Sucursal;
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

        // Sin esto, el admin sembrado queda con id_sucursal null y no puede
        // crear productos, abrir caja, etc. (ver los checks "Tu usuario no
        // tiene una sucursal asignada" en varios controllers) — esto reemplaza
        // al alta de sucursal que antes hacía AuthController::register, que se
        // quitó al pasar de SaaS multi-tenant a instalación de escritorio de
        // un solo local.
        $sucursal = Sucursal::firstOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => 'Casa Central'],
            ['activo' => true, 'es_principal' => true]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'des_usu'         => 'Administrador',
                'password'        => Hash::make('admin123'),
                'id_tipo_usuario' => $tipoAdmin?->id,
                'id_rol'          => $rolAdmin?->id,
                'empresa_id'      => $empresa->id,
                'id_sucursal'     => $sucursal->id,
            ]
        );

        if (!$admin->id_sucursal) {
            $admin->id_sucursal = $sucursal->id;
            $admin->save();
        }

        // El rol NO otorga permisos por sí solo (ver User::chequearPermisos) —
        // lo que decide el acceso real son los permisos directos del usuario.
        // AuthController::register ya hace este mismo sync al crear un admin
        // por el registro público; acá hacía falta lo mismo para que el admin
        // sembrado no quede sin ningún permiso directo.
        if ($rolAdmin) {
            $admin->permisos()->sync($rolAdmin->permisos->pluck('id'));
        }
    }
}
