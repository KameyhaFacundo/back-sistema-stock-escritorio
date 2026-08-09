<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Lote;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Sucursal;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PresupuestosControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Presupuestos ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
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

    private function crearProductoConStock(Empresa $empresa, Sucursal $sucursal, float $stock = 10): Producto
    {
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $producto = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto Test', 'precio' => 100, 'costo' => 50, 'id_categoria' => $categoria->id,
        ]);
        ProductoStock::create(['empresa_id' => $empresa->id, 'id_producto' => $producto->id, 'id_sucursal' => $sucursal->id, 'stock' => $stock]);
        Lote::create(['empresa_id' => $empresa->id, 'id_producto' => $producto->id, 'id_sucursal' => $sucursal->id, 'cantidad' => $stock]);
        return $producto;
    }

    public function test_crear_presupuesto_requiere_permiso(): void
    {
        [, , , $token] = $this->usuarioConPermisos([]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/presupuestos', ['fecha' => now()->format('Y-m-d'), 'lineas' => []]);

        $response->assertStatus(403);
    }

    public function test_crear_presupuesto_no_descuenta_stock(): void
    {
        [, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['create-presupuestos']);
        $producto = $this->crearProductoConStock($empresa, $sucursal, 10);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/presupuestos', [
                'fecha'  => now()->format('Y-m-d'),
                'lineas' => [['id_producto' => $producto->id, 'precio_venta' => 120, 'cantidad' => 3]],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.estado', 'vigente');
        $this->assertEquals(360, (float) $response->json('data.monto_total'));

        $stock = ProductoStock::where('id_producto', $producto->id)->where('id_sucursal', $sucursal->id)->first();
        $this->assertEquals(10, (float) $stock->stock, 'Guardar un presupuesto no toca el stock');

        $this->assertDatabaseMissing('movimientos_stock', ['id_producto' => $producto->id]);
    }

    public function test_convertir_presupuesto_crea_venta_y_descuenta_stock(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['create-presupuestos', 'create-ventas']);
        $producto = $this->crearProductoConStock($empresa, $sucursal, 10);

        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'monto_inicial' => 0, 'fecha' => now()->format('Y-m-d'), 'hora_apertura' => now()->format('H:i'),
        ]);

        $crear = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/presupuestos', [
                'fecha'  => now()->format('Y-m-d'),
                'lineas' => [['id_producto' => $producto->id, 'precio_venta' => 120, 'cantidad' => 3]],
            ]);
        $idPresupuesto = $crear->json('data.id');

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/presupuestos/{$idPresupuesto}/convertir");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.id'), 'Debe devolver la venta creada');

        $stock = ProductoStock::where('id_producto', $producto->id)->where('id_sucursal', $sucursal->id)->first();
        $this->assertEquals(7, (float) $stock->stock, 'Convertir sí descuenta stock (3 de 10)');

        $this->assertDatabaseHas('presupuestos', ['id' => $idPresupuesto, 'estado' => 'convertido']);
    }

    public function test_no_se_puede_convertir_un_presupuesto_ya_convertido(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['create-presupuestos', 'create-ventas']);
        $producto = $this->crearProductoConStock($empresa, $sucursal, 10);

        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'monto_inicial' => 0, 'fecha' => now()->format('Y-m-d'), 'hora_apertura' => now()->format('H:i'),
        ]);

        $crear = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/presupuestos', [
                'fecha'  => now()->format('Y-m-d'),
                'lineas' => [['id_producto' => $producto->id, 'precio_venta' => 120, 'cantidad' => 1]],
            ]);
        $idPresupuesto = $crear->json('data.id');

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/presupuestos/{$idPresupuesto}/convertir")
            ->assertStatus(200);

        $segundaVez = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/presupuestos/{$idPresupuesto}/convertir");

        $segundaVez->assertStatus(422);
    }

    public function test_no_se_puede_convertir_sin_caja_abierta(): void
    {
        [, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['create-presupuestos', 'create-ventas']);
        $producto = $this->crearProductoConStock($empresa, $sucursal, 10);

        $crear = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/presupuestos', [
                'fecha'  => now()->format('Y-m-d'),
                'lineas' => [['id_producto' => $producto->id, 'precio_venta' => 120, 'cantidad' => 1]],
            ]);
        $idPresupuesto = $crear->json('data.id');

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/presupuestos/{$idPresupuesto}/convertir");

        $response->assertStatus(422);
        $this->assertDatabaseHas('presupuestos', ['id' => $idPresupuesto, 'estado' => 'vigente']);
    }

    // Presupuestos.jsx ya no llama a /convertir directo — manda al POS con el
    // carrito armado (ver Home.jsx) y es la venta creada ahí, con
    // id_presupuesto en el payload, la que vincula y marca el presupuesto.
    public function test_confirmar_venta_del_pos_con_id_presupuesto_marca_el_presupuesto_convertido(): void
    {
        // aplicar-descuento-ventas: el presupuesto cotiza $120 pero el producto
        // en catálogo vale $100 — sin este permiso, VentasController::store()
        // rechaza cualquier línea que no coincida con el precio de lista (ver
        // ValidaPreciosLinea), sea la venta manual o venga de un presupuesto.
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['create-presupuestos', 'create-ventas', 'aplicar-descuento-ventas']);
        $producto = $this->crearProductoConStock($empresa, $sucursal, 10);

        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'monto_inicial' => 0, 'fecha' => now()->format('Y-m-d'), 'hora_apertura' => now()->format('H:i'),
        ]);

        $crear = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/presupuestos', [
                'fecha'  => now()->format('Y-m-d'),
                'lineas' => [['id_producto' => $producto->id, 'precio_venta' => 120, 'cantidad' => 3]],
            ]);
        $idPresupuesto = $crear->json('data.id');

        $venta = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/ventas', [
                'id_presupuesto' => $idPresupuesto,
                'fecha'          => now()->format('Y-m-d'),
                'metodo_pago'    => 'efectivo',
                'lineas'         => [['id_producto' => $producto->id, 'precio_venta' => 120, 'cantidad' => 3]],
                'motivo_descuento' => 'Precio cotizado en el presupuesto',
            ]);

        $venta->assertStatus(201);
        $idVenta = $venta->json('data.id');

        $this->assertDatabaseHas('presupuestos', ['id' => $idPresupuesto, 'estado' => 'convertido', 'id_venta' => $idVenta]);
    }

    // Un id_presupuesto que ya no sirve (convertido por otro cajero, borrado,
    // de otra empresa) no puede tirar abajo una venta real — se ignora el
    // vínculo y la venta se registra igual.
    public function test_confirmar_venta_con_id_presupuesto_invalido_no_impide_la_venta(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['create-presupuestos', 'create-ventas', 'aplicar-descuento-ventas']);
        $producto = $this->crearProductoConStock($empresa, $sucursal, 10);

        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'monto_inicial' => 0, 'fecha' => now()->format('Y-m-d'), 'hora_apertura' => now()->format('H:i'),
        ]);

        $crear = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/presupuestos', [
                'fecha'  => now()->format('Y-m-d'),
                'lineas' => [['id_producto' => $producto->id, 'precio_venta' => 120, 'cantidad' => 1]],
            ]);
        $idPresupuesto = $crear->json('data.id');

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/presupuestos/{$idPresupuesto}/convertir")
            ->assertStatus(200);

        $venta = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/ventas', [
                'id_presupuesto' => $idPresupuesto,
                'fecha'          => now()->format('Y-m-d'),
                'metodo_pago'    => 'efectivo',
                'lineas'         => [['id_producto' => $producto->id, 'precio_venta' => 120, 'cantidad' => 1]],
                'motivo_descuento' => 'Precio cotizado en el presupuesto',
            ]);

        $venta->assertStatus(201);
    }
}
