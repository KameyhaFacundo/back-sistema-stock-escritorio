<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Un ajuste manual de stock sin motivo es la tapadera perfecta para un
 * faltante: "cantidad" ya justifica el número que queda, pero nada explica
 * el porqué. create-movimientos ya está en el rol básico "Usuario" por
 * defecto (ver RolSeeder) — el motivo obligatorio es el único freno real.
 */
class MovimientosControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Movimientos ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);

        $usuario = User::create([
            'des_usu' => 'Usuario Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
        ]);
        $ids = Permiso::whereIn('codigo', $codigos)->pluck('id');
        $usuario->permisos()->attach($ids);

        return [$usuario, JWTAuth::fromUser($usuario)];
    }

    // El stock inicial se carga vía StockService::agregar() (no un
    // ProductoStock::create() directo) para que quede respaldado por un
    // lote — restar() valida disponible() sumando `lotes`, no la columna
    // producto_stock.stock: sin esto, cualquier ajuste que RESTE ve 0
    // disponible aunque la columna stock muestre un número > 0.
    private function usuarioConProducto(float $stockInicial = 10): array
    {
        [$usuario, $token] = $this->usuarioConPermisos(['create-movimientos']);
        $empresa = $usuario->empresa;
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $producto = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto Test', 'precio' => 100, 'id_categoria' => $categoria->id,
        ]);
        app(\App\Services\StockService::class)->agregar($producto->id, $usuario->id_sucursal, $stockInicial, $empresa->id);

        return [$usuario, $token, $producto];
    }

    private function agregarStock(int $idProducto, int $idSucursal, int $empresaId, float $cantidad): void
    {
        app(\App\Services\StockService::class)->agregar($idProducto, $idSucursal, $cantidad, $empresaId);
    }

    public function test_rechaza_ajuste_sin_nota(): void
    {
        [, $token] = $this->usuarioConPermisos(['create-movimientos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/movimientos', [
                'producto' => 'Producto suelto', 'tipo' => 'ajuste', 'cantidad' => -1, 'fecha' => now()->format('Y-m-d'),
            ]);

        $response->assertStatus(422);
    }

    public function test_permite_ajuste_con_nota(): void
    {
        [, $token] = $this->usuarioConPermisos(['create-movimientos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/movimientos', [
                'producto' => 'Producto suelto', 'tipo' => 'ajuste', 'cantidad' => -1, 'fecha' => now()->format('Y-m-d'),
                'nota' => 'Rotura durante el reparto',
            ]);

        $response->assertStatus(201);
    }

    // Movimientos de venta/compra ya se justifican por su propio origen —
    // el motivo obligatorio es solo para 'ajuste', el manual sin respaldo.
    public function test_no_exige_nota_para_movimientos_de_venta(): void
    {
        [, $token] = $this->usuarioConPermisos(['create-movimientos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/movimientos', [
                'producto' => 'Producto suelto', 'tipo' => 'venta', 'cantidad' => -1, 'fecha' => now()->format('Y-m-d'),
            ]);

        $response->assertStatus(201);
    }

    // Una SUBA de stock no oculta ningún faltante — el front ya la deja
    // libre y opcional (MOTIVOS_BAJA es solo para bajas), así que acá no
    // hace falta exigir nada.
    public function test_no_exige_nota_para_ajuste_positivo(): void
    {
        [, $token] = $this->usuarioConPermisos(['create-movimientos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/movimientos', [
                'producto' => 'Producto suelto', 'tipo' => 'ajuste', 'cantidad' => 1, 'fecha' => now()->format('Y-m-d'),
            ]);

        $response->assertStatus(201);
    }

    public function test_bulk_store_aplica_varios_ajustes_en_una_sola_request(): void
    {
        [$usuario1, $token, $productoA] = $this->usuarioConProducto(10);
        // Segundo producto de la MISMA empresa, mismo usuario/token.
        $categoria = Categoria::create(['empresa_id' => $usuario1->empresa_id, 'categoria' => 'Otra']);
        $productoB = Producto::create([
            'empresa_id' => $usuario1->empresa_id, 'producto' => 'Producto B', 'precio' => 50, 'id_categoria' => $categoria->id,
        ]);
        $this->agregarStock($productoB->id, $usuario1->id_sucursal, $usuario1->empresa_id, 5);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/movimientos/bulk', [
                'items' => [
                    ['id_producto' => $productoA->id, 'producto' => $productoA->producto, 'cantidad' => 3, 'nota' => ''],
                    ['id_producto' => $productoB->id, 'producto' => $productoB->producto, 'cantidad' => -2, 'nota' => 'Rotura'],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertEquals(2, $response->json('data.aplicados'));
        $this->assertEquals(0, $response->json('data.fallidos'));
        $this->assertEquals(13, (float) ProductoStock::where('id_producto', $productoA->id)->value('stock'));
        $this->assertEquals(3, (float) ProductoStock::where('id_producto', $productoB->id)->value('stock'));
    }

    public function test_bulk_store_saltea_bajas_sin_nota_sin_tumbar_el_resto(): void
    {
        [$usuario, $token, $producto] = $this->usuarioConProducto(10);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/movimientos/bulk', [
                'items' => [
                    ['id_producto' => $producto->id, 'producto' => $producto->producto, 'cantidad' => -1, 'nota' => ''], // sin nota, se saltea
                    ['id_producto' => $producto->id, 'producto' => $producto->producto, 'cantidad' => 2, 'nota' => ''],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertEquals(1, $response->json('data.aplicados'));
        $this->assertEquals(1, $response->json('data.fallidos'));
        // El índice 0 (la baja sin nota) es el que falló — el caller lo usa
        // para saber exactamente qué filas del archivo original se aplicaron
        // de verdad (ver ModalAjusteMasivo en Movimientos.jsx, arma un Excel
        // solo con las aplicadas).
        $this->assertEquals([0], $response->json('data.indices_fallidos'));
        $this->assertEquals(12, (float) ProductoStock::where('id_producto', $producto->id)->value('stock'));
    }

    public function test_bulk_store_no_toca_productos_de_otra_empresa(): void
    {
        [, $token] = $this->usuarioConProducto(10);
        $empresaB = Empresa::create(['nombre' => 'Empresa B ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $categoriaB = Categoria::create(['empresa_id' => $empresaB->id, 'categoria' => 'Cat B']);
        $productoAjeno = Producto::create([
            'empresa_id' => $empresaB->id, 'producto' => 'Ajeno', 'precio' => 100, 'id_categoria' => $categoriaB->id,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/movimientos/bulk', [
                'items' => [
                    ['id_producto' => $productoAjeno->id, 'producto' => $productoAjeno->producto, 'cantidad' => 5, 'nota' => ''],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertEquals(0, $response->json('data.aplicados'));
        $this->assertEquals(1, $response->json('data.fallidos'));
    }
}
