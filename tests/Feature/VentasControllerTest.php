<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Lote;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Cubre ValidaPreciosLinea (App\Http\Controllers\Concerns) — el gate que
 * evita que un cajero sin permiso cobre un producto a un precio distinto
 * del de lista, o cargue un ítem de "monto libre" (sin id_producto, ver
 * handleAgregarMonto en Home.jsx) para entregar un producto real sin dejar
 * rastro. Sin este permiso, el input de precio del carrito queda
 * deshabilitado en el front, pero el backend es la defensa real — el front
 * es solo UX.
 */
class VentasControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function armarEscenario(array $codigosPermisos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Ventas ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $producto = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto de prueba',
            'precio' => 1000, 'costo' => 500, 'id_categoria' => $categoria->id,
        ]);
        ProductoStock::create([
            'empresa_id' => $empresa->id, 'id_producto' => $producto->id, 'id_sucursal' => $sucursal->id,
            'stock' => 10, 'stock_minimo' => 1,
        ]);
        // disponible() (usado para validar y para descontar) suma lotes, no lee
        // producto_stock.stock directo — sin un lote, "Stock insuficiente: 0"
        // aunque producto_stock diga 10 (ver VentaCreacionServiceTest).
        Lote::create([
            'empresa_id' => $empresa->id, 'id_producto' => $producto->id, 'id_sucursal' => $sucursal->id,
            'cantidad' => 10, 'fecha_vencimiento' => now()->addDays(30),
        ]);

        $usuario = User::create([
            'des_usu' => 'Cajero Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
        ]);
        $ids = Permiso::whereIn('codigo', array_merge(['create-caja'], $codigosPermisos))->pluck('id');
        $usuario->permisos()->attach($ids);
        $token = JWTAuth::fromUser($usuario);
        $headers = ['Authorization' => "Bearer {$token}"];

        // Abre caja de verdad (vía el endpoint real) — store() de VentasController
        // rechaza cualquier venta sin un turno abierto.
        $this->withHeaders($headers)->postJson('/api/v1/caja/abrir', ['monto_inicial' => 0]);

        return [$producto, $headers];
    }

    public function test_rechaza_precio_distinto_al_de_lista_sin_permiso(): void
    {
        [$producto, $headers] = $this->armarEscenario(['create-ventas']);

        $response = $this->withHeaders($headers)->postJson('/api/v1/ventas', [
            'fecha' => now()->format('Y-m-d'),
            'metodo_pago' => 'efectivo',
            'lineas' => [['id_producto' => $producto->id, 'cantidad' => 1, 'precio_venta' => 1]],
        ]);

        $response->assertStatus(403);
    }

    public function test_permite_precio_distinto_al_de_lista_con_permiso_y_motivo(): void
    {
        [$producto, $headers] = $this->armarEscenario(['create-ventas', 'aplicar-descuento-ventas']);

        $response = $this->withHeaders($headers)->postJson('/api/v1/ventas', [
            'fecha' => now()->format('Y-m-d'),
            'metodo_pago' => 'efectivo',
            'lineas' => [['id_producto' => $producto->id, 'cantidad' => 1, 'precio_venta' => 1]],
            'motivo_descuento' => 'Cliente frecuente, autorizado por el dueño',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ventas', ['id' => $response->json('data.id'), 'motivo_descuento' => 'Cliente frecuente, autorizado por el dueño']);
    }

    // El permiso solo dice QUIÉN puede descontar — sin el motivo, no hay
    // forma de saber después POR QUÉ lo hizo en un caso puntual.
    public function test_rechaza_descuento_con_permiso_pero_sin_motivo(): void
    {
        [$producto, $headers] = $this->armarEscenario(['create-ventas', 'aplicar-descuento-ventas']);

        $response = $this->withHeaders($headers)->postJson('/api/v1/ventas', [
            'fecha' => now()->format('Y-m-d'),
            'metodo_pago' => 'efectivo',
            'lineas' => [['id_producto' => $producto->id, 'cantidad' => 1, 'precio_venta' => 1]],
        ]);

        $response->assertStatus(422);
    }

    public function test_rechaza_monto_libre_sin_permiso(): void
    {
        [, $headers] = $this->armarEscenario(['create-ventas']);

        $response = $this->withHeaders($headers)->postJson('/api/v1/ventas', [
            'fecha' => now()->format('Y-m-d'),
            'metodo_pago' => 'efectivo',
            'lineas' => [['nombre' => 'Servicio de instalación', 'cantidad' => 1, 'precio_venta' => 500]],
        ]);

        $response->assertStatus(403);
    }

    public function test_permite_monto_libre_con_permiso_y_motivo(): void
    {
        [, $headers] = $this->armarEscenario(['create-ventas', 'aplicar-descuento-ventas']);

        $response = $this->withHeaders($headers)->postJson('/api/v1/ventas', [
            'fecha' => now()->format('Y-m-d'),
            'metodo_pago' => 'efectivo',
            'lineas' => [['nombre' => 'Servicio de instalación', 'cantidad' => 1, 'precio_venta' => 500]],
            'motivo_descuento' => 'Instalación a domicilio, no es un producto del catálogo',
        ]);

        $response->assertStatus(201);
    }

    public function test_rechaza_monto_libre_con_permiso_pero_sin_motivo(): void
    {
        [, $headers] = $this->armarEscenario(['create-ventas', 'aplicar-descuento-ventas']);

        $response = $this->withHeaders($headers)->postJson('/api/v1/ventas', [
            'fecha' => now()->format('Y-m-d'),
            'metodo_pago' => 'efectivo',
            'lineas' => [['nombre' => 'Servicio de instalación', 'cantidad' => 1, 'precio_venta' => 500]],
        ]);

        $response->assertStatus(422);
    }

    public function test_permite_vender_al_precio_de_lista_sin_permiso(): void
    {
        [$producto, $headers] = $this->armarEscenario(['create-ventas']);

        $response = $this->withHeaders($headers)->postJson('/api/v1/ventas', [
            'fecha' => now()->format('Y-m-d'),
            'metodo_pago' => 'efectivo',
            'lineas' => [['id_producto' => $producto->id, 'cantidad' => 1, 'precio_venta' => (float) $producto->precio]],
        ]);

        $response->assertStatus(201);
    }

    // El ajuste global (no por línea) es el mismo tipo de operación — también
    // exige motivo, tanto para descuento como para recargo.
    public function test_rechaza_ajuste_global_con_permiso_pero_sin_motivo(): void
    {
        [$producto, $headers] = $this->armarEscenario(['create-ventas', 'aplicar-descuento-ventas']);

        $response = $this->withHeaders($headers)->postJson('/api/v1/ventas', [
            'fecha' => now()->format('Y-m-d'),
            'metodo_pago' => 'efectivo',
            'lineas' => [['id_producto' => $producto->id, 'cantidad' => 1, 'precio_venta' => (float) $producto->precio]],
            'ajuste' => ['tipo' => 'recargo', 'calculo' => 'porcentaje', 'valor' => 10, 'activo' => true],
        ]);

        $response->assertStatus(422);
    }

    // anular() no tenía ningún test — quedó descubierto justo el código que
    // agrupa las devoluciones parciales por línea (antes: un sum() por línea
    // dentro del loop) al arreglar ese N+1.
    public function test_anular_venta_repone_todo_el_stock_vendido(): void
    {
        [$producto, $headers] = $this->armarEscenario(['create-ventas', 'anular-ventas']);

        $venta = $this->withHeaders($headers)->postJson('/api/v1/ventas', [
            'fecha' => now()->format('Y-m-d'),
            'metodo_pago' => 'efectivo',
            'lineas' => [['id_producto' => $producto->id, 'cantidad' => 3, 'precio_venta' => (float) $producto->precio]],
        ])->json('data');

        $this->assertEquals(7, ProductoStock::where('id_producto', $producto->id)->value('stock'));

        $response = $this->withHeaders($headers)->postJson("/api/v1/ventas/{$venta['id']}/anular");

        $response->assertStatus(200);
        $this->assertEquals(10, ProductoStock::where('id_producto', $producto->id)->value('stock'));
        $this->assertEquals('cancelada', \App\Models\Venta::find($venta['id'])->estado);
    }

    // Cubre justo la línea que cambió: si ya hubo una devolución parcial,
    // anular solo tiene que reponer lo que sigue afuera (no duplicar lo ya
    // devuelto) — antes de hoy esto se calculaba con un sum() por línea.
    public function test_anular_venta_con_devolucion_parcial_previa_no_duplica_la_reposicion(): void
    {
        [$producto, $headers] = $this->armarEscenario(['create-ventas', 'anular-ventas', 'devolver-ventas']);

        $venta = $this->withHeaders($headers)->postJson('/api/v1/ventas', [
            'fecha' => now()->format('Y-m-d'),
            'metodo_pago' => 'efectivo',
            'lineas' => [['id_producto' => $producto->id, 'cantidad' => 5, 'precio_venta' => (float) $producto->precio]],
        ])->json('data');
        $idLinea = $venta['lineas'][0]['id_linea'];

        // Ya se le devolvieron 2 de las 5 al cliente.
        $this->withHeaders($headers)->postJson("/api/v1/ventas/{$venta['id']}/devolucion", [
            'lineas' => [['id_linea_venta' => $idLinea, 'cantidad' => 2]],
        ])->assertStatus(201);
        $this->assertEquals(7, ProductoStock::where('id_producto', $producto->id)->value('stock'));

        // Anular ahora solo debe reponer las 3 que quedaban afuera, no las 5.
        $response = $this->withHeaders($headers)->postJson("/api/v1/ventas/{$venta['id']}/anular");

        $response->assertStatus(200);
        $this->assertEquals(10, ProductoStock::where('id_producto', $producto->id)->value('stock'));
    }
}
