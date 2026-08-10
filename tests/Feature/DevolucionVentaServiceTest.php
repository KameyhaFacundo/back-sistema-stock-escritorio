<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ComboComponente;
use App\Models\Empresa;
use App\Models\GrupoTalle;
use App\Models\LineaVenta;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Sucursal;
use App\Models\Talle;
use App\Models\Turno;
use App\Models\User;
use App\Models\Venta;
use App\Services\DevolucionVentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Devolución parcial de ventas — repone stock, ajusta caja/fiado, respeta
 * límites de cantidad disponible, y prorratea combos/variantes. Corre
 * contra la base real dentro de una transacción (DatabaseTransactions).
 */
class DevolucionVentaServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Empresa $empresa;
    private Sucursal $sucursal;
    private User $usuario;
    private Categoria $categoria;
    private Cliente $cliente;
    private Turno $turno;
    private DevolucionVentaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa  = Empresa::create(['nombre' => 'Test Devolucion Venta ' . uniqid(), 'tipo' => 'almacen']);
        $this->sucursal = Sucursal::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Suc 1', 'activo' => true, 'es_principal' => true]);
        $this->usuario  = User::create([
            'empresa_id' => $this->empresa->id, 'id_sucursal' => $this->sucursal->id, 'des_usu' => 'Cajero',
            'email' => 'c_' . uniqid() . '@test.com', 'password' => bcrypt('123456'), 'is_super_admin' => false,
        ]);
        auth()->login($this->usuario);
        auth('api')->login($this->usuario);

        $this->categoria = Categoria::create(['empresa_id' => $this->empresa->id, 'categoria' => 'General']);
        $this->cliente   = Cliente::create(['empresa_id' => $this->empresa->id, 'persona' => 'Cliente Test', 'estado' => true, 'puntos' => 0]);
        $this->turno     = Turno::create([
            'empresa_id' => $this->empresa->id, 'id_sucursal' => $this->sucursal->id, 'id_usuario' => $this->usuario->nro_usu,
            'estado' => 'abierta', 'fecha' => now()->toDateString(), 'hora_apertura' => '09:00',
            'monto_inicial' => 1000, 'efectivo_actual' => 1300, 'ventas_efectivo' => 300,
        ]);
        $this->service = app(DevolucionVentaService::class);
    }

    private function producto(float $precio = 100): Producto
    {
        $producto = Producto::create([
            'empresa_id' => $this->empresa->id, 'producto' => 'Producto ' . uniqid(), 'codigo' => 'P-' . uniqid(),
            'precio' => $precio, 'costo' => 0, 'estado' => true, 'id_categoria' => $this->categoria->id,
        ]);
        ProductoStock::create(['empresa_id' => $this->empresa->id, 'id_producto' => $producto->id, 'id_sucursal' => $this->sucursal->id, 'stock' => 0, 'stock_minimo' => 1]);
        Lote::create(['empresa_id' => $this->empresa->id, 'id_producto' => $producto->id, 'id_sucursal' => $this->sucursal->id, 'cantidad' => 0]);
        return $producto;
    }

    private function stockDe(Producto $producto): float
    {
        return (float) ProductoStock::where('id_producto', $producto->id)->where('id_sucursal', $this->sucursal->id)->first()->stock;
    }

    private function venta(array $overrides = []): Venta
    {
        return Venta::create(array_merge([
            'empresa_id' => $this->empresa->id, 'id_sucursal' => $this->sucursal->id, 'id_turno' => $this->turno->id,
            'id_cliente' => $this->cliente->id, 'id_usuario' => $this->usuario->nro_usu, 'estado' => 'confirmada',
            'fecha' => now()->toDateString(), 'metodo_pago' => 'efectivo', 'estado_pago' => 'pagado',
            'monto_cobrado' => 0, 'monto_total' => 0, 'cuit' => '0',
        ], $overrides));
    }

    public function test_devolucion_parcial_en_efectivo_repone_stock_y_ajusta_caja(): void
    {
        $producto = $this->producto(100);
        $venta = $this->venta(['monto_cobrado' => 300, 'monto_total' => 300]);
        $linea = LineaVenta::create(['id_venta' => $venta->id, 'id_producto' => $producto->id, 'nombre' => 'Producto', 'precio_venta' => 100, 'cantidad' => 3]);

        $this->service->crear($venta->id, [['id_linea_venta' => $linea->id_linea, 'cantidad' => 1]], 'Cliente se arrepintió');

        $this->assertEquals(1.0, $this->stockDe($producto));
        $this->assertEquals(1200.0, (float) $this->turno->fresh()->efectivo_actual);
        $this->assertEquals(200.0, (float) $venta->fresh()->monto_total);
    }

    public function test_devolucion_en_fiado_con_saldo_a_favor_no_toca_caja(): void
    {
        $producto = $this->producto(100);
        $venta = $this->venta(['metodo_pago' => 'fiado', 'estado_pago' => 'pendiente', 'monto_cobrado' => 0, 'monto_total' => 300]);
        $linea = LineaVenta::create(['id_venta' => $venta->id, 'id_producto' => $producto->id, 'nombre' => 'Producto', 'precio_venta' => 100, 'cantidad' => 3]);

        $efectivoAntes = (float) $this->turno->fresh()->efectivo_actual;
        $devolucion = $this->service->crear($venta->id, [['id_linea_venta' => $linea->id_linea, 'cantidad' => 1]], null);

        $this->assertEquals($efectivoAntes, (float) $this->turno->fresh()->efectivo_actual);
        $this->assertEquals(0.0, (float) $devolucion->monto_efectivo_devuelto);
    }

    public function test_devolucion_en_fiado_ya_pagado_de_mas_devuelve_diferencia_en_efectivo(): void
    {
        $producto = $this->producto(100);
        $venta = $this->venta(['metodo_pago' => 'fiado', 'estado_pago' => 'pagado', 'monto_cobrado' => 300, 'monto_total' => 300]);
        $linea = LineaVenta::create(['id_venta' => $venta->id, 'id_producto' => $producto->id, 'nombre' => 'Producto', 'precio_venta' => 100, 'cantidad' => 3]);

        $efectivoAntes = (float) $this->turno->fresh()->efectivo_actual;
        $this->service->crear($venta->id, [['id_linea_venta' => $linea->id_linea, 'cantidad' => 1]], null);

        $this->assertEquals($efectivoAntes - 100.0, (float) $this->turno->fresh()->efectivo_actual);
        $this->assertEquals(200.0, (float) $venta->fresh()->monto_cobrado);
    }

    // Devolver algo pagado con tarjeta NO debe tocar el arqueo si el cajero
    // eligió resolverlo por transferencia — antes esto restaba de efectivo_actual
    // igual, aunque esa venta nunca metió plata física en la caja.
    public function test_devolucion_de_venta_con_tarjeta_resuelta_por_transferencia_no_toca_caja(): void
    {
        $producto = $this->producto(100);
        $venta = $this->venta(['metodo_pago' => 'tarjeta', 'monto_cobrado' => 300, 'monto_total' => 300]);
        $linea = LineaVenta::create(['id_venta' => $venta->id, 'id_producto' => $producto->id, 'nombre' => 'Producto', 'precio_venta' => 100, 'cantidad' => 3]);

        $efectivoAntes = (float) $this->turno->fresh()->efectivo_actual;
        $devolucion = $this->service->crear($venta->id, [['id_linea_venta' => $linea->id_linea, 'cantidad' => 1]], null, 'transferencia');

        $this->assertEquals($efectivoAntes, (float) $this->turno->fresh()->efectivo_actual);
        $this->assertEquals(0.0, (float) $devolucion->monto_efectivo_devuelto);
        $this->assertEquals('transferencia', $devolucion->forma_reintegro);
        // monto_cobrado sí baja igual — el cliente recuperó ese valor, solo que
        // no en efectivo del cajón.
        $this->assertEquals(200.0, (float) $venta->fresh()->monto_cobrado);
    }

    public function test_no_se_puede_devolver_mas_de_lo_disponible(): void
    {
        $producto = $this->producto(100);
        $venta = $this->venta(['monto_cobrado' => 300, 'monto_total' => 300]);
        $linea = LineaVenta::create(['id_venta' => $venta->id, 'id_producto' => $producto->id, 'nombre' => 'Producto', 'precio_venta' => 100, 'cantidad' => 3]);

        $this->service->crear($venta->id, [['id_linea_venta' => $linea->id_linea, 'cantidad' => 2]], null);

        $this->expectException(\RuntimeException::class);
        $this->service->crear($venta->id, [['id_linea_venta' => $linea->id_linea, 'cantidad' => 2]], null);
    }

    public function test_devolver_todas_las_lineas_cancela_la_venta(): void
    {
        $producto = $this->producto(100);
        $venta = $this->venta(['monto_cobrado' => 300, 'monto_total' => 300]);
        $linea = LineaVenta::create(['id_venta' => $venta->id, 'id_producto' => $producto->id, 'nombre' => 'Producto', 'precio_venta' => 100, 'cantidad' => 3]);

        $this->service->crear($venta->id, [['id_linea_venta' => $linea->id_linea, 'cantidad' => 3]], null);

        $this->assertEquals('cancelada', $venta->fresh()->estado);
    }

    public function test_devolucion_de_combo_prorratea_a_sus_componentes(): void
    {
        $componente = $this->producto(50);
        $combo = Producto::create([
            'empresa_id' => $this->empresa->id, 'producto' => 'Combo', 'codigo' => 'C-' . uniqid(),
            'precio' => 90, 'costo' => 0, 'estado' => true, 'id_categoria' => $this->categoria->id, 'es_combo' => true,
        ]);
        ComboComponente::create(['empresa_id' => $this->empresa->id, 'id_combo' => $combo->id, 'id_producto' => $componente->id, 'cantidad' => 2]);

        $venta = $this->venta(['metodo_pago' => 'fiado', 'estado_pago' => 'pendiente', 'monto_cobrado' => 0, 'monto_total' => 180]);
        $linea = LineaVenta::create(['id_venta' => $venta->id, 'id_producto' => $combo->id, 'nombre' => 'Combo', 'precio_venta' => 90, 'cantidad' => 2]);

        $this->service->crear($venta->id, [['id_linea_venta' => $linea->id_linea, 'cantidad' => 1]], null);

        $this->assertEquals(2.0, $this->stockDe($componente));
    }

    public function test_devolucion_de_variante_por_talle_repone_la_variante_no_el_padre(): void
    {
        $empresaIndument = Empresa::create(['nombre' => 'Test Indument ' . uniqid(), 'tipo' => 'indument']);
        // Reusa la misma sucursal/turno/usuario de la empresa 'almacen' del setUp
        // no aplica acá — se arma todo de nuevo, scopeado a la empresa indumentaria.
        $sucursal = Sucursal::create(['empresa_id' => $empresaIndument->id, 'nombre' => 'Suc 1', 'activo' => true, 'es_principal' => true]);
        $usuario = User::create([
            'empresa_id' => $empresaIndument->id, 'id_sucursal' => $sucursal->id, 'des_usu' => 'Cajero',
            'email' => 'i_' . uniqid() . '@test.com', 'password' => bcrypt('123456'), 'is_super_admin' => false,
        ]);
        auth()->login($usuario);
        auth('api')->login($usuario);

        $categoria = Categoria::create(['empresa_id' => $empresaIndument->id, 'categoria' => 'Remeras']);
        $cliente = Cliente::create(['empresa_id' => $empresaIndument->id, 'persona' => 'Cliente', 'estado' => true, 'puntos' => 0]);
        $turno = Turno::create([
            'empresa_id' => $empresaIndument->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'fecha' => now()->toDateString(), 'hora_apertura' => '09:00',
            'monto_inicial' => 0, 'efectivo_actual' => 0, 'ventas_efectivo' => 0,
        ]);

        $grupo = GrupoTalle::create(['empresa_id' => $empresaIndument->id, 'nombre' => 'Ropa']);
        $talleM = Talle::create(['empresa_id' => $empresaIndument->id, 'id_grupo_talle' => $grupo->id, 'valor' => 'M', 'orden' => 1]);
        $padre = Producto::create([
            'empresa_id' => $empresaIndument->id, 'producto' => 'Remera', 'codigo' => 'REM-' . uniqid(),
            'precio' => 500, 'costo' => 0, 'estado' => true, 'id_categoria' => $categoria->id, 'tiene_variantes' => true, 'id_grupo_talle' => $grupo->id,
        ]);
        $variante = Producto::create([
            'empresa_id' => $empresaIndument->id, 'producto' => 'Remera', 'codigo' => 'REM-M-' . uniqid(),
            'precio' => 500, 'costo' => 0, 'estado' => true, 'id_categoria' => $categoria->id, 'id_producto_padre' => $padre->id, 'id_talle' => $talleM->id,
        ]);
        ProductoStock::create(['empresa_id' => $empresaIndument->id, 'id_producto' => $variante->id, 'id_sucursal' => $sucursal->id, 'stock' => 0, 'stock_minimo' => 1]);
        Lote::create(['empresa_id' => $empresaIndument->id, 'id_producto' => $variante->id, 'id_sucursal' => $sucursal->id, 'cantidad' => 0]);

        $venta = Venta::create([
            'empresa_id' => $empresaIndument->id, 'id_sucursal' => $sucursal->id, 'id_turno' => $turno->id,
            'id_cliente' => $cliente->id, 'id_usuario' => $usuario->nro_usu, 'estado' => 'confirmada',
            'fecha' => now()->toDateString(), 'metodo_pago' => 'fiado', 'estado_pago' => 'pendiente',
            'monto_cobrado' => 0, 'monto_total' => 500, 'cuit' => '0',
        ]);
        $linea = LineaVenta::create(['id_venta' => $venta->id, 'id_producto' => $variante->id, 'nombre' => 'Remera (M)', 'precio_venta' => 500, 'cantidad' => 1]);

        app(DevolucionVentaService::class)->crear($venta->id, [['id_linea_venta' => $linea->id_linea, 'cantidad' => 1]], null);

        $stockVariante = ProductoStock::where('id_producto', $variante->id)->where('id_sucursal', $sucursal->id)->first();
        $stockPadre = ProductoStock::where('id_producto', $padre->id)->where('id_sucursal', $sucursal->id)->first();
        $this->assertEquals(1.0, (float) $stockVariante->stock);
        $this->assertNull($stockPadre);
    }
}
