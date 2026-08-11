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

        // Permisos operativos del día a día (cajero): vende en el POS, consulta
        // clientes y productos, opera la caja de su turno e imprime etiquetas
        // — sin acceso a Compras, Movimientos, Proveedores, Usuarios ni
        // Configuración, y sin ver montos de caja/historial ni más pestañas del
        // dashboard que el resumen (ver comentarios puntuales abajo).
        $permisosUsuario = Permiso::whereIn('codigo', [
            // Imprescindible: tanto el login como el registro navegan a /dashboard
            // apenas se entra, y esa ruta exige este permiso — sin él, cualquier
            // cuenta con este rol queda bloqueada apenas inicia sesión. Sin
            // view-dashboard-completo, esa pantalla le muestra solo "Resumen" —
            // Ventas/Compras/Productos/Métodos de pago/Historial de Caja quedan
            // afuera (mismo detalle que exponen esas secciones enteras, sin
            // acceso propio a algunas de ellas).
            'view-dashboard',
            // Ventas (POS) — sin aplicar-descuento-ventas a propósito: ni el
            // precio del carrito ni un descuento/recargo global, ver
            // ValidaPreciosLinea. Reservado para gerente/admin.
            'list-ventas',
            'view-ventas',
            'create-ventas',
            // Caja — sin list-historial-caja (solo su turno actual, no el de
            // otros cajeros) ni ver-montos-caja (arqueo ciego: no ve los montos
            // hasta cargar su propio conteo al cerrar, ver CajaController).
            'list-caja',
            'create-caja',
            'update-caja',
            // Categorías (solo lectura, para poder navegar productos)
            'list-categorias',
            'view-categorias',
            // Clientes
            'list-clientes',
            'view-clientes',
            'create-clientes',
            'update-clientes',
            // Productos: solo listar/ver — nada de crear, editar, eliminar,
            // "Promoción" ni "Actualizar precios" (ver gestionarProductos en
            // useHasPermiso.jsx, que gatea todo eso del lado del front).
            'list-productos',
            'view-productos',
            // Etiquetas de precio
            'view-etiquetas',
            'print-etiquetas',
            // Compras, Movimientos, Proveedores, Usuarios y Configuración quedan
            // completamente afuera — no hay ningún codigo de esos grupos acá.
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
