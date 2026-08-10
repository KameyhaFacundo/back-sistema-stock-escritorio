<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\LineaVenta;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Cuánto le debe un cliente (y cuánto ya pagó) — no tenía ningún test. Un bug
 * acá misdeclara saldos reales: cobrar de más/de menos, o dejar una venta
 * marcada "pagado" sin estarlo.
 */
class DeudasClientesControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function armarEscenario(array $codigosPermisos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Deudas ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'persona' => 'Cliente Test', 'estado' => true, 'puntos' => 0]);
        $usuario = User::create([
            'des_usu' => 'Usuario Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
        ]);
        $ids = Permiso::whereIn('codigo', $codigosPermisos)->pluck('id');
        $usuario->permisos()->attach($ids);
        $token = JWTAuth::fromUser($usuario);

        return [$empresa, $sucursal, $categoria, $cliente, ['Authorization' => "Bearer {$token}"]];
    }

    private function ventaConDeuda(Empresa $empresa, Sucursal $sucursal, Cliente $cliente, float $total, float $cobrado): Venta
    {
        $estado = $cobrado >= $total ? 'pagado' : ($cobrado > 0 ? 'parcial' : 'pendiente');
        return Venta::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_cliente' => $cliente->id,
            'id_usuario' => 1, 'estado' => 'confirmada', 'fecha' => now()->toDateString(),
            'metodo_pago' => 'cuenta_corriente', 'estado_pago' => $estado,
            'monto_cobrado' => $cobrado, 'monto_total' => $total, 'cuit' => '0',
        ]);
    }

    public function test_cobrar_registra_pago_parcial_y_actualiza_estado(): void
    {
        [$empresa, $sucursal, , $cliente, $headers] = $this->armarEscenario(['update-ventas']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 0);

        $response = $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 400, 'fecha' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(200);
        $fresca = $venta->fresh();
        $this->assertEquals(400, (float) $fresca->monto_cobrado);
        $this->assertEquals('parcial', $fresca->estado_pago);
    }

    // El cajero no debería poder registrar un cobro mayor al saldo real —
    // cobrar() lo capea al saldo pendiente en vez de aceptar el monto tal cual.
    public function test_cobrar_no_permite_superar_el_saldo_pendiente(): void
    {
        [$empresa, $sucursal, , $cliente, $headers] = $this->armarEscenario(['update-ventas']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 800);

        $response = $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 500, 'fecha' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(200);
        $fresca = $venta->fresh();
        $this->assertEquals(1000, (float) $fresca->monto_cobrado);
        $this->assertEquals('pagado', $fresca->estado_pago);
        $this->assertDatabaseHas('pagos_cliente', ['id_venta' => $venta->id, 'monto' => 200]);
    }

    public function test_cobrar_rechaza_una_venta_ya_totalmente_pagada(): void
    {
        [$empresa, $sucursal, , $cliente, $headers] = $this->armarEscenario(['update-ventas']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 1000);

        $response = $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 100, 'fecha' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(400);
        $this->assertEquals(1000, (float) $venta->fresh()->monto_cobrado);
    }

    public function test_index_no_devuelve_ventas_ya_pagadas_por_defecto(): void
    {
        [$empresa, $sucursal, , $cliente, $headers] = $this->armarEscenario(['list-clientes']);
        $pendiente = $this->ventaConDeuda($empresa, $sucursal, $cliente, 500, 0);
        $this->ventaConDeuda($empresa, $sucursal, $cliente, 500, 500);

        $response = $this->withHeaders($headers)->getJson('/api/v1/deudas-clientes');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($pendiente->id));
        $this->assertEquals(1, $ids->count());
    }

    public function test_index_incluir_pagadas_las_suma_al_listado(): void
    {
        [$empresa, $sucursal, , $cliente, $headers] = $this->armarEscenario(['list-clientes']);
        $this->ventaConDeuda($empresa, $sucursal, $cliente, 500, 0);
        $this->ventaConDeuda($empresa, $sucursal, $cliente, 500, 500);

        $response = $this->withHeaders($headers)->getJson('/api/v1/deudas-clientes?incluir_pagadas=1');

        $response->assertStatus(200);
        $this->assertEquals(2, collect($response->json('data.data'))->count());
    }

    public function test_actualizar_precios_recalcula_total_y_estado_pago(): void
    {
        [$empresa, $sucursal, $categoria, $cliente, $headers] = $this->armarEscenario(['update-ventas']);
        $producto = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto', 'precio' => 150, 'costo' => 50, 'id_categoria' => $categoria->id,
        ]);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 200, 200);
        $venta->update(['estado_pago' => 'parcial']); // precio viejo (100) ya estaba "pagado" de más
        LineaVenta::create(['id_venta' => $venta->id, 'id_producto' => $producto->id, 'nombre' => 'Producto', 'precio_venta' => 100, 'precio_original' => 100, 'cantidad' => 2]);

        $response = $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/actualizar-precios");

        $response->assertStatus(200);
        // 2 unidades al precio ACTUAL del producto (150) = 300, ya cobrado 200 -> parcial.
        $this->assertEquals(300, (float) $venta->fresh()->monto_total);
        $this->assertEquals('parcial', $venta->fresh()->estado_pago);
    }

    public function test_revertir_precios_vuelve_al_precio_original_de_la_linea(): void
    {
        [$empresa, $sucursal, $categoria, $cliente, $headers] = $this->armarEscenario(['update-ventas']);
        $producto = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto', 'precio' => 150, 'costo' => 50, 'id_categoria' => $categoria->id,
        ]);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 300, 100);
        LineaVenta::create(['id_venta' => $venta->id, 'id_producto' => $producto->id, 'nombre' => 'Producto', 'precio_venta' => 150, 'precio_original' => 100, 'cantidad' => 2]);

        $response = $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/revertir-precios");

        $response->assertStatus(200);
        // Vuelve a 2 × 100 (precio_original) = 200, ya cobrado 100 -> parcial.
        $this->assertEquals(200, (float) $venta->fresh()->monto_total);
        $this->assertEquals('parcial', $venta->fresh()->estado_pago);
    }
}
