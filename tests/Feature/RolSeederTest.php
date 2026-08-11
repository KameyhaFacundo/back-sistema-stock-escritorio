<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Rol;
use Database\Seeders\RolSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Rol básico "Usuario" (cajero): vende en el POS, consulta clientes y
 * productos, opera su caja e imprime etiquetas — sin Compras, Movimientos,
 * Proveedores, Usuarios, Configuración NI Reportes/Dashboard (ni siquiera
 * "Resumen"), sin montos/historial de caja, y sin poder tocar precios ni
 * aplicar descuentos (reservado para gerente/admin).
 */
class RolSeederTest extends TestCase
{
    use DatabaseTransactions;

    private function permisosDelRol(string $codigoRol): \Illuminate\Support\Collection
    {
        (new RolSeeder())->run();

        return Rol::where('codigo', $codigoRol)->firstOrFail()->permisos->pluck('codigo');
    }

    public function test_rol_usuario_tiene_exactamente_los_permisos_basicos(): void
    {
        $codigos = $this->permisosDelRol('usuario')->sort()->values();

        $esperados = [
            'list-ventas', 'view-ventas', 'create-ventas',
            'list-caja', 'create-caja', 'update-caja',
            'list-categorias', 'view-categorias',
            'list-clientes', 'view-clientes', 'create-clientes', 'update-clientes',
            'list-productos', 'view-productos',
            'view-etiquetas', 'print-etiquetas',
        ];
        sort($esperados);

        $this->assertEquals($esperados, $codigos->all());
    }

    public function test_rol_usuario_no_tiene_compras_movimientos_proveedores_ni_configuracion(): void
    {
        $codigos = $this->permisosDelRol('usuario');

        foreach (['compras', 'movimientos', 'proveedores', 'usuarios', 'configuracion', 'roles', 'permisos', 'sucursales'] as $grupo) {
            $idsDelGrupo = Permiso::where('grupo', $grupo)->pluck('codigo');
            $this->assertEmpty(
                $codigos->intersect($idsDelGrupo)->all(),
                "El rol usuario no debería tener ningún permiso del grupo '{$grupo}'"
            );
        }
    }

    public function test_rol_usuario_no_puede_aplicar_descuentos_ni_ver_montos_o_historial_de_caja(): void
    {
        $codigos = $this->permisosDelRol('usuario');

        foreach (['aplicar-descuento-ventas', 'ver-montos-caja', 'list-historial-caja', 'view-dashboard-completo', 'view-dashboard'] as $codigo) {
            $this->assertNotContains($codigo, $codigos, "El rol usuario no debería tener '{$codigo}'");
        }
    }

    public function test_rol_gerente_mantiene_devolver_compras_y_dashboard_completo(): void
    {
        $codigos = $this->permisosDelRol('gerente');

        $this->assertContains('devolver-compras', $codigos);
        $this->assertContains('view-dashboard-completo', $codigos);
    }
}
