<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\LineaVenta;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Turno;
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

        return [$empresa, $sucursal, $categoria, $cliente, ['Authorization' => "Bearer {$token}"], $usuario];
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
            'monto' => 400, 'fecha' => now()->format('Y-m-d'), 'nota' => 'Pagó en el local',
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
            'monto' => 500, 'fecha' => now()->format('Y-m-d'), 'nota' => 'Pagó en el local',
        ]);

        $response->assertStatus(200);
        $fresca = $venta->fresh();
        $this->assertEquals(1000, (float) $fresca->monto_cobrado);
        $this->assertEquals('pagado', $fresca->estado_pago);
        $this->assertDatabaseHas('pagos_cliente', ['id_venta' => $venta->id, 'monto' => 200]);
    }

    // El caso que no tenía ningún rastro: cobrar() ahora deja un movimiento de
    // caja individual, además de sumar al contador "ventas_efectivo".
    public function test_cobrar_deja_un_movimiento_de_caja_con_el_nombre_del_cliente(): void
    {
        [$empresa, $sucursal, , $cliente, $headers, $usuario] = $this->armarEscenario(['update-ventas']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 0);
        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'fecha' => now()->toDateString(), 'hora_apertura' => '09:00',
            'monto_inicial' => 500, 'efectivo_actual' => 500, 'ventas_efectivo' => 0,
        ]);

        $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 400, 'fecha' => now()->format('Y-m-d'), 'nota' => 'Pagó en el local',
        ])->assertStatus(200);

        $movimiento = \App\Models\MovimientoCaja::where('monto', 400)->where('tipo', 'ingreso')->first();
        $this->assertNotNull($movimiento);
        $this->assertStringContainsString($cliente->persona, $movimiento->motivo);
        $this->assertStringContainsString((string) $venta->id, $movimiento->motivo);
    }

    public function test_cobrar_rechaza_una_venta_ya_totalmente_pagada(): void
    {
        [$empresa, $sucursal, , $cliente, $headers] = $this->armarEscenario(['update-ventas']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 1000);

        $response = $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 100, 'fecha' => now()->format('Y-m-d'), 'nota' => 'Pagó en el local',
        ]);

        $response->assertStatus(400);
        $this->assertEquals(1000, (float) $venta->fresh()->monto_cobrado);
    }

    public function test_resumen_desglosa_lo_cobrado_por_metodo(): void
    {
        [$empresa, $sucursal, , $cliente, $headers] = $this->armarEscenario(['update-ventas', 'list-clientes']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 0);
        $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 300, 'fecha' => now()->format('Y-m-d'), 'metodo_pago' => 'efectivo', 'nota' => 'Efectivo en el local',
        ])->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 200, 'fecha' => now()->format('Y-m-d'), 'metodo_pago' => 'transferencia', 'nota' => 'Transferencia bancaria',
        ])->assertStatus(200);

        $response = $this->withHeaders($headers)->getJson('/api/v1/deudas-clientes/resumen');

        $response->assertStatus(200);
        $fila = collect($response->json('data'))->firstWhere('id_cliente', $cliente->id);
        $this->assertNotNull($fila);
        $this->assertEquals(300, $fila['cobrado_por_metodo']['efectivo']);
        $this->assertEquals(200, $fila['cobrado_por_metodo']['transferencia']);
        $this->assertEquals(500, (float) $fila['total_cobrado']);
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

    // Un cobro sin ninguna referencia es mucho más fácil de inventar — ver el
    // comentario en DeudasClientesController::cobrar.
    public function test_cobrar_rechaza_sin_nota(): void
    {
        [$empresa, $sucursal, , $cliente, $headers] = $this->armarEscenario(['update-ventas']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 0);

        $response = $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 400, 'fecha' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(422);
        $this->assertEquals(0, (float) $venta->fresh()->monto_cobrado);
    }

    // Antes, un cobro por transferencia no dejaba NINGÚN rastro en Caja — así
    // que declarar en falso "transferencia" para un cobro que en realidad fue
    // en efectivo era invisible en cualquier arqueo.
    public function test_cobrar_por_transferencia_deja_movimiento_sin_tocar_efectivo(): void
    {
        [$empresa, $sucursal, , $cliente, $headers, $usuario] = $this->armarEscenario(['update-ventas']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 0);
        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'fecha' => now()->toDateString(), 'hora_apertura' => '09:00',
            'monto_inicial' => 500, 'efectivo_actual' => 500, 'ventas_efectivo' => 0,
        ]);

        $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 400, 'fecha' => now()->format('Y-m-d'), 'metodo_pago' => 'transferencia', 'nota' => 'Transferencia bancaria',
        ])->assertStatus(200);

        $movimiento = \App\Models\MovimientoCaja::where('monto', 400)->where('tipo', 'ingreso')->first();
        $this->assertNotNull($movimiento);
        $this->assertEquals('transferencia', $movimiento->metodo);
        $this->assertEquals(500, (float) \App\Models\Turno::first()->efectivo_actual);
    }

    // El recibo automático es lo único que no depende de que el empleado
    // quiera avisarle al cliente — ver ComprobantePagoMail.
    public function test_cobrar_manda_comprobante_por_mail_si_el_cliente_tiene_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        [$empresa, $sucursal, , , $headers] = $this->armarEscenario(['update-ventas']);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'persona' => 'Cliente Con Mail', 'estado' => true, 'puntos' => 0, 'email' => 'cliente@test.com']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 0);

        $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 400, 'fecha' => now()->format('Y-m-d'), 'nota' => 'Pagó en el local',
        ])->assertStatus(200);

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ComprobantePagoMail::class, fn ($mail) => $mail->hasTo('cliente@test.com'));
    }

    // Sin email cargado, el cobro tiene que seguir funcionando igual — no
    // debe bloquearse por no tener a quién mandarle el comprobante.
    public function test_cobrar_no_falla_si_el_cliente_no_tiene_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        [$empresa, $sucursal, , $cliente, $headers] = $this->armarEscenario(['update-ventas']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 0);

        $response = $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 400, 'fecha' => now()->format('Y-m-d'), 'nota' => 'Pagó en el local',
        ]);

        $response->assertStatus(200);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    // Anular una venta con un cobro ya registrado NO debe borrar el registro
    // del pago — antes desaparecía sin dejar rastro de que había existido.
    public function test_anular_venta_marca_el_pago_como_anulado_en_vez_de_borrarlo(): void
    {
        [$empresa, $sucursal, , $cliente, $headers, $usuario] = $this->armarEscenario(['update-ventas', 'anular-ventas']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 0);
        $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 400, 'fecha' => now()->format('Y-m-d'), 'nota' => 'Pagó en el local',
        ])->assertStatus(200);
        $pago = \App\Models\PagoCliente::where('id_venta', $venta->id)->firstOrFail();

        $response = $this->withHeaders($headers)->postJson("/api/v1/ventas/{$venta->id}/anular");

        $response->assertStatus(200);
        $this->assertDatabaseHas('pagos_cliente', ['id' => $pago->id]);
        $pagoFresco = $pago->fresh();
        $this->assertTrue($pagoFresco->anulado);
        $this->assertEquals($usuario->nro_usu, $pagoFresco->id_usuario_anulacion);
        $this->assertNotNull($pagoFresco->fecha_anulacion);
    }

    // Un cobro por transferencia que se anula tiene que revertir el
    // "esperado por transferencia" del turno (ver arqueo en Caja.jsx) — sin
    // esto, la plata seguía contando como si la venta anulada nunca se
    // hubiera revertido.
    public function test_anular_venta_con_cobro_por_transferencia_revierte_el_movimiento(): void
    {
        [$empresa, $sucursal, , $cliente, $headers, $usuario] = $this->armarEscenario(['update-ventas', 'anular-ventas']);
        $venta = $this->ventaConDeuda($empresa, $sucursal, $cliente, 1000, 0);
        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'fecha' => now()->toDateString(), 'hora_apertura' => '09:00',
            'monto_inicial' => 500, 'efectivo_actual' => 500, 'ventas_efectivo' => 0,
        ]);
        $this->withHeaders($headers)->postJson("/api/v1/deudas-clientes/{$venta->id}/cobrar", [
            'monto' => 400, 'fecha' => now()->format('Y-m-d'), 'metodo_pago' => 'transferencia', 'nota' => 'Transferencia bancaria',
        ])->assertStatus(200);

        $this->withHeaders($headers)->postJson("/api/v1/ventas/{$venta->id}/anular")->assertStatus(200);

        $reversion = \App\Models\MovimientoCaja::where('tipo', 'egreso')->where('metodo', 'transferencia')->where('monto', 400)->first();
        $this->assertNotNull($reversion);
        $this->assertStringContainsString((string) $venta->id, $reversion->motivo);
    }
}
