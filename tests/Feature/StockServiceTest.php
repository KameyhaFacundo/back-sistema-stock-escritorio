<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Sucursal;
use App\Services\StockService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre el punto de escritura único de stock (StockService) — se prueba
 * contra la base real dentro de una transacción (DatabaseTransactions),
 * así que cada test corre y se revierte solo sin dejar datos de prueba.
 */
class StockServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function producto(): Producto
    {
        return Producto::where('es_combo', false)->firstOrFail();
    }

    private function sucursal(Producto $producto): Sucursal
    {
        return Sucursal::where('empresa_id', $producto->empresa_id)->firstOrFail();
    }

    public function test_restar_lanza_excepcion_si_no_hay_stock_suficiente(): void
    {
        $producto = $this->producto();
        $sucursal = $this->sucursal($producto);
        $service  = app(StockService::class);

        ProductoStock::updateOrCreate(
            ['id_producto' => $producto->id, 'id_sucursal' => $sucursal->id],
            ['empresa_id' => $producto->empresa_id, 'stock' => 3, 'stock_minimo' => 1]
        );
        Lote::where('id_producto', $producto->id)->where('id_sucursal', $sucursal->id)->delete();
        Lote::create(['empresa_id' => $producto->empresa_id, 'id_producto' => $producto->id, 'id_sucursal' => $sucursal->id, 'cantidad' => 3]);

        $this->expectException(\RuntimeException::class);
        $service->restar($producto->id, $sucursal->id, 10, $producto->empresa_id);
    }

    public function test_restar_consume_lotes_en_orden_fefo(): void
    {
        $producto = $this->producto();
        $sucursal = $this->sucursal($producto);
        $service  = app(StockService::class);

        ProductoStock::updateOrCreate(
            ['id_producto' => $producto->id, 'id_sucursal' => $sucursal->id],
            ['empresa_id' => $producto->empresa_id, 'stock' => 10, 'stock_minimo' => 1]
        );
        Lote::where('id_producto', $producto->id)->where('id_sucursal', $sucursal->id)->delete();
        $loteViejo = Lote::create(['empresa_id' => $producto->empresa_id, 'id_producto' => $producto->id, 'id_sucursal' => $sucursal->id, 'cantidad' => 4, 'fecha_vencimiento' => now()->addDays(5)]);
        $loteNuevo = Lote::create(['empresa_id' => $producto->empresa_id, 'id_producto' => $producto->id, 'id_sucursal' => $sucursal->id, 'cantidad' => 6, 'fecha_vencimiento' => now()->addDays(30)]);

        $service->restar($producto->id, $sucursal->id, 5, $producto->empresa_id);

        $this->assertEquals(0, (float) $loteViejo->fresh()->cantidad, 'El lote que vence antes se tiene que consumir primero');
        $this->assertEquals(5, (float) $loteNuevo->fresh()->cantidad);
        $stock = ProductoStock::where('id_producto', $producto->id)->where('id_sucursal', $sucursal->id)->first();
        $this->assertEquals(5, $stock->stock);
    }

    public function test_agregar_crea_lote_con_vencimiento_y_suma_stock(): void
    {
        $producto = $this->producto();
        $sucursal = $this->sucursal($producto);
        $service  = app(StockService::class);

        ProductoStock::updateOrCreate(
            ['id_producto' => $producto->id, 'id_sucursal' => $sucursal->id],
            ['empresa_id' => $producto->empresa_id, 'stock' => 0, 'stock_minimo' => 1]
        );
        Lote::where('id_producto', $producto->id)->where('id_sucursal', $sucursal->id)->delete();

        $vencimiento = now()->addDays(15)->format('Y-m-d');
        $service->agregar($producto->id, $sucursal->id, 8, $producto->empresa_id, $vencimiento);

        $lote = Lote::where('id_producto', $producto->id)->where('id_sucursal', $sucursal->id)->first();
        $this->assertNotNull($lote);
        $this->assertEquals(8, (float) $lote->cantidad);
        $this->assertEquals($vencimiento, $lote->fecha_vencimiento->format('Y-m-d'));

        $stock = ProductoStock::where('id_producto', $producto->id)->where('id_sucursal', $sucursal->id)->first();
        $this->assertEquals(8, $stock->stock);
    }

    public function test_transferir_mueve_stock_entre_sucursales(): void
    {
        $producto = $this->producto();
        $origen   = $this->sucursal($producto);
        $destino  = Sucursal::where('empresa_id', $producto->empresa_id)->where('id', '!=', $origen->id)->first()
            ?? Sucursal::create(['empresa_id' => $producto->empresa_id, 'nombre' => 'Sucursal Test Transfer', 'activo' => true]);

        $service = app(StockService::class);

        ProductoStock::updateOrCreate(['id_producto' => $producto->id, 'id_sucursal' => $origen->id], ['empresa_id' => $producto->empresa_id, 'stock' => 10, 'stock_minimo' => 1]);
        ProductoStock::updateOrCreate(['id_producto' => $producto->id, 'id_sucursal' => $destino->id], ['empresa_id' => $producto->empresa_id, 'stock' => 0, 'stock_minimo' => 1]);
        Lote::where('id_producto', $producto->id)->where('id_sucursal', $origen->id)->delete();
        Lote::create(['empresa_id' => $producto->empresa_id, 'id_producto' => $producto->id, 'id_sucursal' => $origen->id, 'cantidad' => 10]);

        $usuario = \App\Models\User::where('empresa_id', $producto->empresa_id)->firstOrFail();
        $resultado = $service->transferir($producto, $origen->id, $destino->id, 4, $usuario->nro_usu);

        $this->assertEquals('transferencia_salida', $resultado['salida']->tipo);
        $this->assertEquals('transferencia_entrada', $resultado['entrada']->tipo);

        $stockOrigen  = ProductoStock::where('id_producto', $producto->id)->where('id_sucursal', $origen->id)->first();
        $stockDestino = ProductoStock::where('id_producto', $producto->id)->where('id_sucursal', $destino->id)->first();
        $this->assertEquals(6, $stockOrigen->stock);
        $this->assertEquals(4, $stockDestino->stock);
    }
}
