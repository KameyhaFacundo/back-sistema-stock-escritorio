<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\GrupoTalle;
use App\Models\Lote;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Sucursal;
use App\Models\Talle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Cubre la toma de inventario masiva (POST /inventario/guardar) — no tenía
 * ningún test antes, justo el endpoint donde se encontró y arregló un N+1
 * (Producto::find() por fila del loop en vez de un whereIn() antes de entrar).
 */
class InventarioControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function armarEscenario(): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Inventario ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $usuario = User::create([
            'des_usu' => 'Usuario Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
        ]);
        $ids = Permiso::whereIn('codigo', ['create-movimientos'])->pluck('id');
        $usuario->permisos()->attach($ids);
        $token = JWTAuth::fromUser($usuario);

        return [$empresa, $sucursal, $categoria, ['Authorization' => "Bearer {$token}"]];
    }

    private function crearProducto(Empresa $empresa, Sucursal $sucursal, Categoria $categoria, float $stock): Producto
    {
        $producto = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto ' . uniqid(),
            'precio' => 100, 'costo' => 50, 'id_categoria' => $categoria->id,
        ]);
        ProductoStock::create([
            'empresa_id' => $empresa->id, 'id_producto' => $producto->id, 'id_sucursal' => $sucursal->id,
            'stock' => $stock, 'stock_minimo' => 1,
        ]);
        if ($stock > 0) {
            Lote::create([
                'empresa_id' => $empresa->id, 'id_producto' => $producto->id, 'id_sucursal' => $sucursal->id,
                'cantidad' => $stock, 'fecha_vencimiento' => now()->addDays(30),
            ]);
        }
        return $producto;
    }

    public function test_ajusta_stock_cuando_hay_diferencia(): void
    {
        [$empresa, $sucursal, $categoria, $headers] = $this->armarEscenario();
        $producto = $this->crearProducto($empresa, $sucursal, $categoria, 10);

        $response = $this->withHeaders($headers)->postJson('/api/v1/inventario/guardar', [
            'productos' => [
                ['id_producto' => $producto->id, 'stock_fisico' => 7, 'producto' => $producto->producto, 'codigo' => $producto->codigo],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('ajustes'));
        $this->assertEquals(7, ProductoStock::where('id_producto', $producto->id)->value('stock'));
    }

    public function test_no_ajusta_si_el_stock_fisico_coincide(): void
    {
        [$empresa, $sucursal, $categoria, $headers] = $this->armarEscenario();
        $producto = $this->crearProducto($empresa, $sucursal, $categoria, 10);

        $response = $this->withHeaders($headers)->postJson('/api/v1/inventario/guardar', [
            'productos' => [
                ['id_producto' => $producto->id, 'stock_fisico' => 10, 'producto' => $producto->producto, 'codigo' => $producto->codigo],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('ajustes'));
        $this->assertEquals(1, $response->json('sin_cambios'));
    }

    public function test_saltea_productos_con_variantes(): void
    {
        [$empresa, $sucursal, $categoria, $headers] = $this->armarEscenario();
        $grupo = GrupoTalle::create(['empresa_id' => $empresa->id, 'nombre' => 'Grupo Test']);
        Talle::create(['empresa_id' => $empresa->id, 'id_grupo_talle' => $grupo->id, 'valor' => 'M', 'orden' => 1]);
        $padre = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Remera', 'precio' => 100, 'costo' => 50,
            'id_categoria' => $categoria->id, 'tiene_variantes' => true, 'id_grupo_talle' => $grupo->id,
        ]);

        $response = $this->withHeaders($headers)->postJson('/api/v1/inventario/guardar', [
            'productos' => [
                ['id_producto' => $padre->id, 'stock_fisico' => 5, 'producto' => $padre->producto, 'codigo' => $padre->codigo],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('ajustes'));
        $this->assertEquals(1, $response->json('salteados_variantes'));
    }

    // El fix del N+1 batchea el fetch de todos los productos en un solo whereIn()
    // antes del loop — este test confirma que ese batching no rompe el resultado
    // por fila cuando hay más de un producto en la misma toma de inventario.
    public function test_ajusta_varios_productos_en_la_misma_toma(): void
    {
        [$empresa, $sucursal, $categoria, $headers] = $this->armarEscenario();
        $productoA = $this->crearProducto($empresa, $sucursal, $categoria, 10);
        $productoB = $this->crearProducto($empresa, $sucursal, $categoria, 20);

        $response = $this->withHeaders($headers)->postJson('/api/v1/inventario/guardar', [
            'productos' => [
                ['id_producto' => $productoA->id, 'stock_fisico' => 8,  'producto' => $productoA->producto, 'codigo' => $productoA->codigo],
                ['id_producto' => $productoB->id, 'stock_fisico' => 25, 'producto' => $productoB->producto, 'codigo' => $productoB->codigo],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('ajustes'));
        $this->assertEquals(8,  ProductoStock::where('id_producto', $productoA->id)->value('stock'));
        $this->assertEquals(25, ProductoStock::where('id_producto', $productoB->id)->value('stock'));
    }
}
