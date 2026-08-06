<?php

namespace Database\Seeders;

use App\Models\Permiso;
use Illuminate\Database\Seeder;

class PermisoSeeder extends Seeder
{
    public function run(): void
    {
        $permisos = [
            // Dashboard
            ['codigo' => 'view-dashboard', 'nombre' => 'Ver dashboard', 'grupo' => 'dashboard'],

            // General — controles compartidos por varias pantallas
            ['codigo' => 'ver-filtros-fechas', 'nombre' => 'Cambiar filtros de fecha (por defecto: solo el día de hoy)', 'grupo' => 'general'],

            // Usuarios
            ['codigo' => 'list-usuarios', 'nombre' => 'Listar usuarios', 'grupo' => 'usuarios'],
            ['codigo' => 'view-usuarios', 'nombre' => 'Ver usuario', 'grupo' => 'usuarios'],
            ['codigo' => 'create-usuarios', 'nombre' => 'Crear usuario', 'grupo' => 'usuarios'],
            ['codigo' => 'update-usuarios', 'nombre' => 'Actualizar usuario', 'grupo' => 'usuarios'],
            ['codigo' => 'delete-usuarios', 'nombre' => 'Eliminar usuario', 'grupo' => 'usuarios'],
            ['codigo' => 'restore-usuarios', 'nombre' => 'Restaurar usuario', 'grupo' => 'usuarios'],

            // Roles
            ['codigo' => 'list-roles', 'nombre' => 'Listar roles', 'grupo' => 'roles'],
            ['codigo' => 'view-roles', 'nombre' => 'Ver rol', 'grupo' => 'roles'],
            ['codigo' => 'create-roles', 'nombre' => 'Crear rol', 'grupo' => 'roles'],
            ['codigo' => 'update-roles', 'nombre' => 'Actualizar rol', 'grupo' => 'roles'],
            ['codigo' => 'delete-roles', 'nombre' => 'Eliminar rol', 'grupo' => 'roles'],

            // Permisos
            ['codigo' => 'list-permisos', 'nombre' => 'Listar permisos', 'grupo' => 'permisos'],
            ['codigo' => 'assign-permisos', 'nombre' => 'Asignar permisos', 'grupo' => 'permisos'],

            // Configuración
            ['codigo' => 'view-configuracion', 'nombre' => 'Ver configuración', 'grupo' => 'configuracion'],
            ['codigo' => 'update-configuracion', 'nombre' => 'Actualizar configuración', 'grupo' => 'configuracion'],

            // Categorías
            ['codigo' => 'list-categorias', 'nombre' => 'Listar categorías', 'grupo' => 'categorias'],
            ['codigo' => 'view-categorias', 'nombre' => 'Ver categoría', 'grupo' => 'categorias'],
            ['codigo' => 'create-categorias', 'nombre' => 'Crear categoría', 'grupo' => 'categorias'],
            ['codigo' => 'update-categorias', 'nombre' => 'Actualizar categoría', 'grupo' => 'categorias'],
            ['codigo' => 'delete-categorias', 'nombre' => 'Eliminar categoría', 'grupo' => 'categorias'],

            // Productos
            ['codigo' => 'list-productos', 'nombre' => 'Listar productos', 'grupo' => 'productos'],
            ['codigo' => 'view-productos', 'nombre' => 'Ver producto', 'grupo' => 'productos'],
            ['codigo' => 'create-productos', 'nombre' => 'Crear producto', 'grupo' => 'productos'],
            ['codigo' => 'update-productos', 'nombre' => 'Actualizar producto', 'grupo' => 'productos'],
            ['codigo' => 'delete-productos', 'nombre' => 'Eliminar producto', 'grupo' => 'productos'],

            // Proveedores
            ['codigo' => 'list-proveedores', 'nombre' => 'Listar proveedores', 'grupo' => 'proveedores'],
            ['codigo' => 'view-proveedores', 'nombre' => 'Ver proveedor', 'grupo' => 'proveedores'],
            ['codigo' => 'create-proveedores', 'nombre' => 'Crear proveedor', 'grupo' => 'proveedores'],
            ['codigo' => 'update-proveedores', 'nombre' => 'Actualizar proveedor', 'grupo' => 'proveedores'],
            ['codigo' => 'delete-proveedores', 'nombre' => 'Eliminar proveedor', 'grupo' => 'proveedores'],

            // Clientes
            ['codigo' => 'list-clientes', 'nombre' => 'Listar clientes', 'grupo' => 'clientes'],
            ['codigo' => 'view-clientes', 'nombre' => 'Ver cliente', 'grupo' => 'clientes'],
            ['codigo' => 'create-clientes', 'nombre' => 'Crear cliente', 'grupo' => 'clientes'],
            ['codigo' => 'update-clientes', 'nombre' => 'Actualizar cliente', 'grupo' => 'clientes'],
            ['codigo' => 'delete-clientes', 'nombre' => 'Eliminar cliente', 'grupo' => 'clientes'],

            // Compras
            ['codigo' => 'list-compras', 'nombre' => 'Listar compras', 'grupo' => 'compras'],
            ['codigo' => 'view-compras', 'nombre' => 'Ver compra', 'grupo' => 'compras'],
            ['codigo' => 'create-compras', 'nombre' => 'Crear compra', 'grupo' => 'compras'],
            ['codigo' => 'update-compras', 'nombre' => 'Actualizar compra', 'grupo' => 'compras'],
            ['codigo' => 'delete-compras', 'nombre' => 'Eliminar compra', 'grupo' => 'compras'],
            ['codigo' => 'change-status-compras', 'nombre' => 'Cambiar estado de compra', 'grupo' => 'compras'],
            ['codigo' => 'devolver-compras', 'nombre' => 'Devolver mercadería de una compra', 'grupo' => 'compras'],

            // Ventas
            ['codigo' => 'list-ventas', 'nombre' => 'Listar ventas', 'grupo' => 'ventas'],
            ['codigo' => 'view-ventas', 'nombre' => 'Ver venta', 'grupo' => 'ventas'],
            ['codigo' => 'create-ventas', 'nombre' => 'Crear venta', 'grupo' => 'ventas'],
            ['codigo' => 'update-ventas', 'nombre' => 'Actualizar venta', 'grupo' => 'ventas'],
            ['codigo' => 'delete-ventas', 'nombre' => 'Eliminar venta', 'grupo' => 'ventas'],
            ['codigo' => 'change-status-ventas', 'nombre' => 'Cambiar estado de venta', 'grupo' => 'ventas'],
            ['codigo' => 'aplicar-descuento-ventas', 'nombre' => 'Aplicar descuento en venta', 'grupo' => 'ventas'],
            ['codigo' => 'anular-ventas', 'nombre' => 'Anular venta', 'grupo' => 'ventas'],
            ['codigo' => 'devolver-ventas', 'nombre' => 'Devolver productos de una venta', 'grupo' => 'ventas'],

            // Presupuestos
            ['codigo' => 'list-presupuestos', 'nombre' => 'Listar presupuestos', 'grupo' => 'presupuestos'],
            ['codigo' => 'view-presupuestos', 'nombre' => 'Ver presupuesto', 'grupo' => 'presupuestos'],
            ['codigo' => 'create-presupuestos', 'nombre' => 'Crear presupuesto', 'grupo' => 'presupuestos'],
            ['codigo' => 'update-presupuestos', 'nombre' => 'Actualizar presupuesto', 'grupo' => 'presupuestos'],
            ['codigo' => 'delete-presupuestos', 'nombre' => 'Eliminar presupuesto', 'grupo' => 'presupuestos'],

            // Caja
            ['codigo' => 'list-caja',           'nombre' => 'Ver estado de caja',   'grupo' => 'caja'],
            ['codigo' => 'create-caja',         'nombre' => 'Abrir caja / registrar movimiento', 'grupo' => 'caja'],
            ['codigo' => 'update-caja',         'nombre' => 'Cerrar caja',          'grupo' => 'caja'],
            ['codigo' => 'list-historial-caja', 'nombre' => 'Ver historial de caja', 'grupo' => 'caja'],
            ['codigo' => 'ver-montos-caja',     'nombre' => 'Ver montos de caja (monto inicial, ventas efectivo, ingresos/egresos manuales)', 'grupo' => 'caja'],

            // Movimientos de stock
            ['codigo' => 'list-movimientos',   'nombre' => 'Listar movimientos de stock', 'grupo' => 'movimientos'],
            ['codigo' => 'create-movimientos', 'nombre' => 'Registrar ajuste de stock',    'grupo' => 'movimientos'],
            // Usado por PUT lotes/{id} — faltaba en el catálogo, la ruta quedaba
            // inalcanzable para todos (ni el admin puede tener un permiso que no existe).
            ['codigo' => 'update-movimientos', 'nombre' => 'Editar lote de inventario',    'grupo' => 'movimientos'],
            ['codigo' => 'delete-movimientos', 'nombre' => 'Eliminar movimiento de stock', 'grupo' => 'movimientos'],

            // Auditoría
            ['codigo' => 'list-carritos-vaciados', 'nombre' => 'Ver carritos vaciados (auditoría)', 'grupo' => 'auditoria'],

            // Etiquetas de precio
            ['codigo' => 'view-etiquetas',  'nombre' => 'Ver módulo de etiquetas',    'grupo' => 'etiquetas'],
            ['codigo' => 'print-etiquetas', 'nombre' => 'Imprimir etiquetas de precio', 'grupo' => 'etiquetas'],

            // Sucursales
            ['codigo' => 'list-sucursales',   'nombre' => 'Listar sucursales',   'grupo' => 'sucursales'],
            ['codigo' => 'create-sucursales', 'nombre' => 'Crear sucursal',      'grupo' => 'sucursales'],
            ['codigo' => 'update-sucursales', 'nombre' => 'Actualizar sucursal', 'grupo' => 'sucursales'],
            ['codigo' => 'delete-sucursales', 'nombre' => 'Eliminar sucursal',   'grupo' => 'sucursales'],
        ];

        foreach ($permisos as $permiso) {
            Permiso::firstOrCreate(
                ['codigo' => $permiso['codigo']],
                $permiso
            );
        }
    }
}
