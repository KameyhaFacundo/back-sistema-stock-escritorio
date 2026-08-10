<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Lote;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Sucursal;
use App\Models\Turno;
use App\Models\User;
use App\Services\VentaCreacionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La venta es la baja de stock más frecuente del sistema — acá se verifica
 * que consuma stock correctamente y respete el orden FEFO de los lotes
 * (se rompió una vez ya: las ventas descontaban producto_stock.stock
 * directo sin tocar `lotes`, ver VentaCreacionService::crear()).
 *
 * armarEscenario() arma su propia Empresa/Producto/Usuario en vez de
 * buscar filas ya existentes (antes: `firstOrFail()`) — en una base recién
 * migrada/seedeada (el caso real de una instalación nueva, sin ningún
 * producto todavía) esto tiraba ModelNotFoundException. Solo "funcionaba"
 * antes por datos sueltos que habían quedado de pruebas manuales
 * anteriores en esa base puntual.
 */
class VentaCreacionServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function armarEscenario(): array
    {
        $empresa = Empresa::create(['nombre' => 'Test VentaCreacion ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);
        $producto = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto de prueba',
            'precio' => 100, 'costo' => 50, 'id_categoria' => $categoria->id,
        ]);
        $usuario = User::create([
            'des_usu' => 'Usuario Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
        ]);

        ProductoStock::updateOrCreate(
            ['id_producto' => $producto->id, 'id_sucursal' => $sucursal->id],
            ['empresa_id' => $producto->empresa_id, 'stock' => 10, 'stock_minimo' => 1]
        );
        Lote::where('id_producto', $producto->id)->where('id_sucursal', $sucursal->id)->delete();
        $loteViejo = Lote::create(['empresa_id' => $producto->empresa_id, 'id_producto' => $producto->id, 'id_sucursal' => $sucursal->id, 'cantidad' => 4, 'fecha_vencimiento' => now()->addDays(5)]);
        $loteNuevo = Lote::create(['empresa_id' => $producto->empresa_id, 'id_producto' => $producto->id, 'id_sucursal' => $sucursal->id, 'cantidad' => 6, 'fecha_vencimiento' => now()->addDays(30)]);

        $turno = Turno::firstOrCreate(
            ['id_usuario' => $usuario->nro_usu, 'estado' => 'abierta'],
            ['empresa_id' => $producto->empresa_id, 'id_sucursal' => $sucursal->id, 'monto_inicial' => 0, 'fecha' => now()->format('Y-m-d'), 'hora_apertura' => now()->format('H:i')]
        );

        return compact('producto', 'usuario', 'sucursal', 'loteViejo', 'loteNuevo', 'turno');
    }

    public function test_crear_venta_descuenta_stock_y_consume_lotes_fefo(): void
    {
        ['producto' => $producto, 'usuario' => $usuario, 'sucursal' => $sucursal, 'loteViejo' => $loteViejo, 'loteNuevo' => $loteNuevo, 'turno' => $turno] = $this->armarEscenario();

        $venta = app(VentaCreacionService::class)->crear([
            'lineas'      => [['id_producto' => $producto->id, 'cantidad' => 5, 'precio_venta' => (float) $producto->precio]],
            'metodo_pago' => 'efectivo',
            'id_usuario'  => $usuario->nro_usu,
            'fecha'       => now()->format('Y-m-d'),
            'hora'        => now()->format('H:i'),
        ], $producto->empresa_id, $turno->id);

        $this->assertNotNull($venta->id);
        $this->assertEquals(0, (float) $loteViejo->fresh()->cantidad, 'El lote más próximo a vencer se consume primero');
        $this->assertEquals(5, (float) $loteNuevo->fresh()->cantidad);

        $stock = ProductoStock::where('id_producto', $producto->id)->where('id_sucursal', $sucursal->id)->first();
        $this->assertEquals(5, $stock->stock, 'producto_stock.stock debe coincidir con lo que queda en los lotes');
    }

    // Regresión de performance: el chequeo de stock (lockOrCrear + disponible)
    // se hacía dos veces por producto en cada venta — una vez para validar
    // antes de descontar, y otra vez de nuevo adentro de StockService::restar().
    // Este test falla si esa duplicación vuelve, contando cuántas queries
    // "producto_stock"/"lotes" corren para una venta de un solo producto.
    public function test_crear_venta_no_repite_el_chequeo_de_stock_por_producto(): void
    {
        ['producto' => $producto, 'usuario' => $usuario, 'turno' => $turno] = $this->armarEscenario();

        $queriesStock = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queriesStock) {
            if (str_contains($query->sql, '"producto_stock"') || str_contains($query->sql, '"lotes"')) {
                $queriesStock++;
            }
        });

        app(VentaCreacionService::class)->crear([
            'lineas'      => [['id_producto' => $producto->id, 'cantidad' => 1, 'precio_venta' => (float) $producto->precio]],
            'metodo_pago' => 'efectivo',
            'id_usuario'  => $usuario->nro_usu,
            'fecha'       => now()->format('Y-m-d'),
            'hora'        => now()->format('H:i'),
        ], $producto->empresa_id, $turno->id);

        // 1 SELECT (lockOrCrear) + 1 SUM (disponible) + 1 SELECT de lotes a
        // consumir + 1 UPDATE de producto_stock + N UPDATE de lotes tocados —
        // sin la duplicación no debería superar un puñado bajo de consultas,
        // nunca el doble de eso.
        $this->assertLessThanOrEqual(6, $queriesStock, 'El chequeo de stock parece estar repitiéndose por producto otra vez');
    }

    public function test_movimiento_de_venta_describe_el_id_real_no_el_ticket_interno(): void
    {
        ['producto' => $producto, 'usuario' => $usuario, 'turno' => $turno] = $this->armarEscenario();

        // numero_ticket es el código interno que genera Home.jsx offline (ej.
        // "MSGXHK4D-9361") para no depender del servidor al armar la venta —
        // no es legible para el usuario, así que el movimiento de stock debe
        // describir la venta por su id real, no por ese código.
        $venta = app(VentaCreacionService::class)->crear([
            'lineas'        => [['id_producto' => $producto->id, 'cantidad' => 1, 'precio_venta' => (float) $producto->precio]],
            'metodo_pago'   => 'efectivo',
            'numero_ticket' => 'MSGXHK4D-9361',
            'id_usuario'    => $usuario->nro_usu,
            'fecha'         => now()->format('Y-m-d'),
            'hora'          => now()->format('H:i'),
        ], $producto->empresa_id, $turno->id);

        $movimiento = MovimientoStock::where('id_producto', $producto->id)->where('tipo', 'venta')->latest('id')->first();
        $this->assertEquals("Venta #{$venta->id}", $movimiento->sub_tipo);
    }

    public function test_crear_venta_rechaza_si_no_hay_stock_suficiente(): void
    {
        ['producto' => $producto, 'usuario' => $usuario, 'turno' => $turno] = $this->armarEscenario();

        $this->expectException(\RuntimeException::class);

        app(VentaCreacionService::class)->crear([
            'lineas'      => [['id_producto' => $producto->id, 'cantidad' => 999, 'precio_venta' => (float) $producto->precio]],
            'metodo_pago' => 'efectivo',
            'id_usuario'  => $usuario->nro_usu,
            'fecha'       => now()->format('Y-m-d'),
            'hora'        => now()->format('H:i'),
        ], $producto->empresa_id, $turno->id);
    }
}
