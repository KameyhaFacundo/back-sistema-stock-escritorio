<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Categoria;
use App\Models\LineaVenta;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class DashboardControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Dashboard ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);

        $usuario = User::create([
            'des_usu' => 'Usuario Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
        ]);

        if ($codigos) {
            $ids = Permiso::whereIn('codigo', $codigos)->pluck('id');
            $usuario->permisos()->attach($ids);
        }

        $token = JWTAuth::fromUser($usuario);

        return [$usuario, $empresa, $sucursal, $token];
    }

    private function crearProducto(Empresa $empresa, array $overrides = []): Producto
    {
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        return Producto::create(array_merge([
            'empresa_id' => $empresa->id, 'producto' => 'Producto ' . uniqid(),
            'precio' => 100, 'costo' => 50, 'id_categoria' => $categoria->id,
        ], $overrides));
    }

    private function crearVentaConLinea(Empresa $empresa, Producto $producto, array $ventaOverrides, float $cantidad, float $precio): Venta
    {
        $idUsuario = User::where('empresa_id', $empresa->id)->value('nro_usu');

        $venta = Venta::create(array_merge([
            'empresa_id' => $empresa->id, 'id_usuario' => $idUsuario, 'estado' => 'confirmada',
            'fecha' => now()->format('Y-m-d'), 'monto_total' => $precio * $cantidad, 'cuit' => '20304050607',
        ], $ventaOverrides));

        LineaVenta::create([
            'id_venta' => $venta->id, 'id_producto' => $producto->id,
            'precio_venta' => $precio, 'cantidad' => $cantidad,
        ]);

        return $venta;
    }

    public function test_ranking_productos_requiere_permiso(): void
    {
        [, , , $token] = $this->usuarioConPermisos([]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/dashboard/ranking-productos');

        $response->assertStatus(403);
    }

    public function test_mas_vendidos_agrupa_variantes_y_excluye_ventas_canceladas(): void
    {
        [, $empresa, , $token] = $this->usuarioConPermisos(['view-dashboard']);

        $padre = $this->crearProducto($empresa, ['tiene_variantes' => true]);
        $varianteA = $this->crearProducto($empresa, ['id_producto_padre' => $padre->id]);
        $varianteB = $this->crearProducto($empresa, ['id_producto_padre' => $padre->id]);
        $otro = $this->crearProducto($empresa);

        $hoy = now()->format('Y-m-d');
        $this->crearVentaConLinea($empresa, $varianteA, ['fecha' => $hoy], 3, 100);
        $this->crearVentaConLinea($empresa, $varianteB, ['fecha' => $hoy], 2, 100);
        $this->crearVentaConLinea($empresa, $otro, ['fecha' => $hoy], 1, 100);
        // Cancelada — no debe sumar.
        $this->crearVentaConLinea($empresa, $varianteA, ['fecha' => $hoy, 'estado' => 'cancelada'], 999, 100);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/dashboard/ranking-productos?desde={$hoy}&hasta={$hoy}");

        $response->assertStatus(200);
        $masVendidos = collect($response->json('data.masVendidos'));

        $filaPadre = $masVendidos->firstWhere('id', $padre->id);
        $this->assertNotNull($filaPadre, 'Las variantes se agrupan bajo el id del producto padre');
        $this->assertEquals(5.0, $filaPadre['unidades'], 'Suma de las 2 variantes, sin contar la venta cancelada');

        $filaOtro = $masVendidos->firstWhere('id', $otro->id);
        $this->assertEquals(1.0, $filaOtro['unidades']);
    }

    /**
     * Regresión: antes Dashboard.jsx sumaba stock×costo/precio sobre el
     * array de productos cargado en memoria (limitado a 500) — con un
     * catálogo más grande el valor quedaba subestimado sin que nadie lo
     * notara. Ahora lo suma el backend, sobre TODA la base, no una página.
     */
    public function test_stats_incluye_valor_de_inventario_sumado_en_la_base(): void
    {
        [, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['view-dashboard']);

        $a = $this->crearProducto($empresa, ['costo' => 50, 'precio' => 100]);
        ProductoStock::create(['empresa_id' => $empresa->id, 'id_producto' => $a->id, 'id_sucursal' => $sucursal->id, 'stock' => 10, 'stock_minimo' => 5]);
        $b = $this->crearProducto($empresa, ['costo' => 20, 'precio' => 40]);
        ProductoStock::create(['empresa_id' => $empresa->id, 'id_producto' => $b->id, 'id_sucursal' => $sucursal->id, 'stock' => 5, 'stock_minimo' => 5]);
        // Inactivo — no debe contarse.
        $inactivo = $this->crearProducto($empresa, ['costo' => 999, 'precio' => 999, 'estado' => false]);
        ProductoStock::create(['empresa_id' => $empresa->id, 'id_producto' => $inactivo->id, 'id_sucursal' => $sucursal->id, 'stock' => 10, 'stock_minimo' => 5]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/dashboard/stats');

        $response->assertStatus(200);
        // 10*50 + 5*20 = 600 de costo; 10*100 + 5*40 = 1200 de venta.
        $this->assertEquals(600, $response->json('data.valorInventario.costo'));
        $this->assertEquals(1200, $response->json('data.valorInventario.venta'));
    }

    public function test_sin_movimiento_lista_solo_productos_sin_ventas_recientes_y_con_stock(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['view-dashboard']);

        $conVentaReciente = $this->crearProducto($empresa, ['costo' => 20]);
        $sinVentas        = $this->crearProducto($empresa, ['costo' => 20]);
        $sinStock         = $this->crearProducto($empresa, ['costo' => 20]);

        // Se excluyen los productos dados de alta DESPUÉS del inicio de la
        // ventana de "sin movimiento" (no tuvieron ni chance de venderse) —
        // sin este backdate, los 3 quedarían afuera por ser recién creados.
        foreach ([$conVentaReciente, $sinVentas, $sinStock] as $p) {
            $p->created_at = now()->subDays(60);
            $p->save();
            ProductoStock::create(['empresa_id' => $empresa->id, 'id_producto' => $p->id, 'id_sucursal' => $sucursal->id, 'stock' => $p->id === $sinStock->id ? 0 : 10]);
        }

        $this->crearVentaConLinea($empresa, $conVentaReciente, ['fecha' => now()->format('Y-m-d')], 1, 100);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/dashboard/ranking-productos?dias=30');

        $response->assertStatus(200);
        $sinMovimiento = collect($response->json('data.sinMovimiento'))->pluck('id');

        $this->assertTrue($sinMovimiento->contains($sinVentas->id), 'Sin ventas y con stock: debe aparecer');
        $this->assertFalse($sinMovimiento->contains($conVentaReciente->id), 'Con venta reciente: no debe aparecer');
        $this->assertFalse($sinMovimiento->contains($sinStock->id), 'Sin stock: no tiene sentido marcarlo como capital parado');
    }
}
