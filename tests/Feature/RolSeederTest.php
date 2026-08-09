<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Rol;
use Database\Seeders\RolSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * devolver-compras salió del rol básico "Usuario" (vendedor) — una
 * devolución de compra baja el stock de forma "legítima", tapadera
 * perfecta para encubrir un robo. Mismo criterio que ya reservaba
 * anular-ventas/aplicar-descuento-ventas para gerente/admin.
 */
class RolSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_rol_usuario_no_incluye_devolver_compras(): void
    {
        (new RolSeeder())->run();

        $rolUsuario = Rol::where('codigo', 'usuario')->firstOrFail();
        $idDevolverCompras = Permiso::where('codigo', 'devolver-compras')->value('id');

        $this->assertFalse(
            $rolUsuario->permisos()->where('permisos.id', $idDevolverCompras)->exists(),
            'devolver-compras no debería estar en el rol básico'
        );
        // Control: el resto de los permisos de compras del vendedor común
        // sigue intacto, no se rompió nada más al sacar este.
        $this->assertTrue(
            $rolUsuario->permisos()->whereIn('permisos.codigo', ['create-compras', 'update-compras'])->count() === 2
        );
    }

    public function test_rol_gerente_mantiene_devolver_compras(): void
    {
        (new RolSeeder())->run();

        $rolGerente = Rol::where('codigo', 'gerente')->firstOrFail();
        $idDevolverCompras = Permiso::where('codigo', 'devolver-compras')->value('id');

        $this->assertTrue(
            $rolGerente->permisos()->where('permisos.id', $idDevolverCompras)->exists()
        );
    }
}
