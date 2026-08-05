<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\LineaCompra;
use App\Models\Lote;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\Turno;
use App\Models\User;
use App\Services\DevolucionCompraService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Devolución parcial de compras (devolver mercadería al proveedor) — espejo
 * de DevolucionVentaServiceTest: descuenta stock, ajusta caja/cuenta
 * corriente, respeta límites de cantidad disponible.
 */
class DevolucionCompraServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Empresa $empresa;
    private Sucursal $sucursal;
    private User $usuario;
    private Categoria $categoria;
    private Proveedor $proveedor;
    private Turno $turno;
    private DevolucionCompraService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa   = Empresa::create(['nombre' => 'Test Devolucion Compra ' . uniqid(), 'tipo' => 'almacen']);
        $this->sucursal  = Sucursal::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Suc 1', 'activo' => true, 'es_principal' => true]);
        $this->usuario   = User::create([
            'empresa_id' => $this->empresa->id, 'id_sucursal' => $this->sucursal->id, 'des_usu' => 'Cajero',
            'email' => 'c_' . uniqid() . '@test.com', 'password' => bcrypt('123456'), 'is_super_admin' => false,
        ]);
        auth()->login($this->usuario);
        auth('api')->login($this->usuario);

        $this->categoria = Categoria::create(['empresa_id' => $this->empresa->id, 'categoria' => 'General']);
        $this->proveedor = Proveedor::create(['empresa_id' => $this->empresa->id, 'cuit' => '20111111111', 'persona' => 'Proveedor Test', 'estado' => true]);
        $this->turno     = Turno::create([
            'empresa_id' => $this->empresa->id, 'id_sucursal' => $this->sucursal->id, 'id_usuario' => $this->usuario->nro_usu,
            'estado' => 'abierta', 'fecha' => now()->toDateString(), 'hora_apertura' => '09:00',
            'monto_inicial' => 1000, 'efectivo_actual' => 700, 'ventas_efectivo' => 0,
        ]);
        $this->service = app(DevolucionCompraService::class);
    }

    private function producto(float $precio = 100, float $stockInicial = 10): Producto
    {
        $producto = Producto::create([
            'empresa_id' => $this->empresa->id, 'producto' => 'Producto ' . uniqid(), 'codigo' => 'P-' . uniqid(),
            'precio' => $precio, 'costo' => 0, 'estado' => true, 'id_categoria' => $this->categoria->id,
        ]);
        ProductoStock::create(['empresa_id' => $this->empresa->id, 'id_producto' => $producto->id, 'id_sucursal' => $this->sucursal->id, 'stock' => $stockInicial, 'stock_minimo' => 1]);
        Lote::create(['empresa_id' => $this->empresa->id, 'id_producto' => $producto->id, 'id_sucursal' => $this->sucursal->id, 'cantidad' => $stockInicial]);
        return $producto;
    }

    private function stockDe(Producto $producto): float
    {
        return (float) ProductoStock::where('id_producto', $producto->id)->where('id_sucursal', $this->sucursal->id)->first()->stock;
    }

    private function compra(array $overrides = []): Compra
    {
        return Compra::create(array_merge([
            'empresa_id' => $this->empresa->id, 'id_sucursal' => $this->sucursal->id, 'id_proveedor' => $this->proveedor->id,
            'id_usuario' => $this->usuario->nro_usu, 'estado' => 'confirmada', 'fecha' => now()->toDateString(),
            'metodo_pago' => 'efectivo', 'estado_deuda' => 'pagado', 'monto_pagado' => 0, 'monto_total' => 0, 'cuit' => '0',
        ], $overrides));
    }

    public function test_devolucion_parcial_en_efectivo_descuenta_stock_y_devuelve_plata(): void
    {
        $producto = $this->producto(100, 10);
        $compra = $this->compra(['monto_pagado' => 300, 'monto_total' => 300]);
        $linea = LineaCompra::create(['id_compra' => $compra->id, 'id_producto' => $producto->id, 'precio_compra' => 100, 'cantidad' => 3]);

        $this->service->crear($compra->id, [['id_linea_compra' => $linea->id_linea, 'cantidad' => 1]], 'Mercadería dañada');

        $this->assertEquals(9.0, $this->stockDe($producto));
        $this->assertEquals(800.0, (float) $this->turno->fresh()->efectivo_actual);
        $this->assertEquals(200.0, (float) $compra->fresh()->monto_total);
    }

    public function test_devolucion_deja_registro_en_movimientos_stock(): void
    {
        $producto = $this->producto(100, 10);
        $compra = $this->compra(['monto_pagado' => 300, 'monto_total' => 300]);
        $linea = LineaCompra::create(['id_compra' => $compra->id, 'id_producto' => $producto->id, 'precio_compra' => 100, 'cantidad' => 3]);

        $this->service->crear($compra->id, [['id_linea_compra' => $linea->id_linea, 'cantidad' => 1]], null);

        $this->assertTrue(
            MovimientoStock::where('id_producto', $producto->id)->where('sub_tipo', "Devolución de compra #{$compra->id}")->exists()
        );
    }

    public function test_cuenta_corriente_con_saldo_pendiente_de_sobra_no_toca_caja(): void
    {
        $producto = $this->producto(100, 10);
        $compra = $this->compra(['metodo_pago' => 'cuenta_corriente', 'estado_deuda' => 'pendiente', 'monto_pagado' => 0, 'monto_total' => 300]);
        $linea = LineaCompra::create(['id_compra' => $compra->id, 'id_producto' => $producto->id, 'precio_compra' => 100, 'cantidad' => 3]);

        $efectivoAntes = (float) $this->turno->fresh()->efectivo_actual;
        $this->service->crear($compra->id, [['id_linea_compra' => $linea->id_linea, 'cantidad' => 1]], null);

        $this->assertEquals($efectivoAntes, (float) $this->turno->fresh()->efectivo_actual);
    }

    public function test_no_se_puede_devolver_mas_de_lo_disponible(): void
    {
        $producto = $this->producto(100, 10);
        $compra = $this->compra(['monto_pagado' => 300, 'monto_total' => 300]);
        $linea = LineaCompra::create(['id_compra' => $compra->id, 'id_producto' => $producto->id, 'precio_compra' => 100, 'cantidad' => 3]);

        $this->service->crear($compra->id, [['id_linea_compra' => $linea->id_linea, 'cantidad' => 2]], null);

        $this->expectException(\RuntimeException::class);
        $this->service->crear($compra->id, [['id_linea_compra' => $linea->id_linea, 'cantidad' => 2]], null);
    }
}
