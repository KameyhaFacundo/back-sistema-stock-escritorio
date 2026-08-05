<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\LineaCompra;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductosControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos, ?Empresa $empresa = null): array
    {
        $empresa = $empresa ?? Empresa::create(['nombre' => 'Test Productos ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);

        $usuario = User::create([
            'des_usu'    => 'Usuario Test',
            'email'      => 'test' . uniqid() . '@test.com',
            'password'   => bcrypt('password'),
            'empresa_id' => $empresa->id,
        ]);

        if ($codigos) {
            $ids = Permiso::whereIn('codigo', $codigos)->pluck('id');
            $usuario->permisos()->attach($ids);
        }

        $token = JWTAuth::fromUser($usuario);

        return [$usuario, $empresa, $token];
    }

    public function test_index_requiere_permiso(): void
    {
        [, , $token] = $this->usuarioConPermisos([]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/productos');

        $response->assertStatus(403);
    }

    public function test_index_no_devuelve_productos_de_otra_empresa(): void
    {
        $empresaB = Empresa::create(['nombre' => 'Empresa B ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $categoriaB = Categoria::create(['empresa_id' => $empresaB->id, 'categoria' => 'Cat B']);
        Producto::create([
            'empresa_id' => $empresaB->id, 'producto' => 'Producto de otra empresa',
            'precio' => 100, 'id_categoria' => $categoriaB->id,
        ]);

        [, , $token] = $this->usuarioConPermisos(['list-productos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/productos');

        $response->assertStatus(200);
        $nombres = collect($response->json('data.data') ?? $response->json('data'))->pluck('producto');
        $this->assertFalse($nombres->contains('Producto de otra empresa'));
    }

    public function test_store_crea_producto_valido(): void
    {
        [$usuario, $empresa, $token] = $this->usuarioConPermisos(['create-productos']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);
        $usuario->update(['id_sucursal' => $sucursal->id]);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/productos', [
                'producto' => 'Coca Cola 500ml',
                'precio' => 1500,
                'id_categoria' => $categoria->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('productos', [
            'producto' => 'Coca Cola 500ml',
            'empresa_id' => $empresa->id,
        ]);
    }

    public function test_store_falla_sin_categoria(): void
    {
        [, , $token] = $this->usuarioConPermisos(['create-productos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/productos', [
                'producto' => 'Producto sin categoría',
                'precio' => 100,
            ]);

        $response->assertStatus(422);
    }

    public function test_historial_compras_agrupa_por_proveedor_con_el_precio_de_cada_vez(): void
    {
        [$usuario, $empresa, $token] = $this->usuarioConPermisos(['view-productos']);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $producto = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Tornillo 3/4', 'precio' => 100, 'id_categoria' => $categoria->id,
        ]);
        $proveedorA = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Proveedor A', 'cuit' => '20111111112']);
        $proveedorB = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Proveedor B', 'cuit' => '20222222223']);

        $compraA = Compra::create([
            'empresa_id' => $empresa->id, 'id_proveedor' => $proveedorA->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'confirmada', 'fecha' => '2026-01-10', 'monto_total' => 50, 'cuit' => '20304050607',
        ]);
        LineaCompra::create(['id_compra' => $compraA->id, 'id_producto' => $producto->id, 'precio_compra' => 50, 'cantidad' => 1]);

        $compraB = Compra::create([
            'empresa_id' => $empresa->id, 'id_proveedor' => $proveedorB->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'confirmada', 'fecha' => '2026-02-10', 'monto_total' => 65, 'cuit' => '20304050607',
        ]);
        LineaCompra::create(['id_compra' => $compraB->id, 'id_producto' => $producto->id, 'precio_compra' => 65, 'cantidad' => 1]);

        // Compra pendiente (nunca confirmada) — no debería contarse como un costo real pagado.
        $compraPendiente = Compra::create([
            'empresa_id' => $empresa->id, 'id_proveedor' => $proveedorA->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'pendiente', 'fecha' => '2026-03-01', 'monto_total' => 999, 'cuit' => '20304050607',
        ]);
        LineaCompra::create(['id_compra' => $compraPendiente->id, 'id_producto' => $producto->id, 'precio_compra' => 999, 'cantidad' => 1]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/productos/{$producto->id}/historial-compras");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        // Más reciente primero.
        $this->assertEquals('65.00', $data[0]['precio_compra']);
        $this->assertEquals('Proveedor B', $data[0]['compra']['proveedor']['persona']);
        $this->assertEquals('50.00', $data[1]['precio_compra']);
        $this->assertEquals('Proveedor A', $data[1]['compra']['proveedor']['persona']);
    }
}
