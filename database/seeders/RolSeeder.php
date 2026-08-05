<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;

class RolSeeder extends Seeder
{
    public function run(): void
    {
        // Rol Administrador - todos los permisos
        $admin = Rol::firstOrCreate(
            ['codigo' => 'admin'],
            [
                'nombre' => 'Administrador',
                'descripcion' => 'Acceso total al sistema',
            ]
        );

        // Asignar todos los permisos al admin
        $todosLosPermisos = Permiso::pluck('id');
        $admin->permisos()->sync($todosLosPermisos);

        // Rol Usuario - permisos básicos
        $usuario = Rol::firstOrCreate(
            ['codigo' => 'usuario'],
            [
                'nombre' => 'Usuario',
                'descripcion' => 'Usuario estándar con permisos limitados',
            ]
        );

        // Permisos operativos del día a día (vendedor): puede operar caja, cargar
        // ventas/compras/movimientos y consultar clientes/proveedores/productos,
        // pero no gestiona usuarios/roles/permisos, no elimina nada, y no puede
        // modificar ni anular ventas ya cerradas (eso queda para gerente/admin).
        $permisosUsuario = Permiso::whereIn('codigo', [
            // Imprescindible: tanto el login como el registro navegan a /dashboard
            // apenas se entra, y esa ruta exige este permiso — sin él, cualquier
            // cuenta con este rol queda bloqueada apenas inicia sesión.
            'view-dashboard',
            // Movimientos de stock
            'list-movimientos',
            'create-movimientos',
            // Ventas
            'list-ventas',
            'view-ventas',
            'create-ventas',
            // Caja
            'list-caja',
            'create-caja',
            'update-caja',
            'list-historial-caja',
            'ver-montos-caja',
            // Categorías (solo lectura)
            'list-categorias',
            'view-categorias',
            // Clientes
            'list-clientes',
            'view-clientes',
            'create-clientes',
            'update-clientes',
            // Compras
            'list-compras',
            'view-compras',
            'create-compras',
            'update-compras',
            'change-status-compras',
            'devolver-compras',
            // Productos (solo lectura)
            'list-productos',
            'view-productos',
            // Proveedores
            'list-proveedores',
            'view-proveedores',
            'create-proveedores',
            'update-proveedores',
        ])->pluck('id');
        $usuario->permisos()->sync($permisosUsuario);

        // Rol Gerente
        $gerente = Rol::firstOrCreate(
            ['codigo' => 'gerente'],
            [
                'nombre' => 'Gerente',
                'descripcion' => 'Gestión de usuarios',
            ]
        );

        // Asignar permisos de gestión (excepto eliminar usuarios y roles)
        $permisosGerente = Permiso::whereNotIn('codigo', [
            'delete-usuarios',
            'delete-roles',
            'create-roles',
            'update-roles',
        ])->pluck('id');
        $gerente->permisos()->sync($permisosGerente);
    }
}
