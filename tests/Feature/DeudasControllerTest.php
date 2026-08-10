<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Cuánto le debés a un proveedor (y pagos parciales) — no tenía ningún test.
 * Cubre también que pagar() deje un movimiento de caja visible, algo que
 * antes no pasaba (a diferencia de una compra pagada al contado, que sí
 * generaba un renglón en Movimientos vía ComprasController::ajustarCajaCompra()).
 */
class DeudasControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function armarEscenario(float $totalCompra, float $yaPagado = 0): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Deudas Prov ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);
        $proveedor = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Proveedor Test']);
        $usuario = User::create([
            'des_usu' => 'Usuario Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
        ]);
        $ids = Permiso::whereIn('codigo', ['update-compras'])->pluck('id');
        $usuario->permisos()->attach($ids);
        $token = JWTAuth::fromUser($usuario);

        $estado = $yaPagado >= $totalCompra ? 'pagado' : ($yaPagado > 0 ? 'parcial' : 'pendiente');
        $compra = Compra::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_proveedor' => $proveedor->id,
            'id_usuario' => $usuario->nro_usu, 'estado' => 'confirmada', 'fecha' => now()->format('Y-m-d'),
            'metodo_pago' => 'cuenta_corriente', 'estado_deuda' => $estado,
            'monto_total' => $totalCompra, 'monto_pagado' => $yaPagado, 'cuit' => '0',
        ]);
        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'fecha' => now()->toDateString(), 'hora_apertura' => '09:00',
            'monto_inicial' => 1000, 'efectivo_actual' => 1000, 'ventas_efectivo' => 0,
        ]);

        return [$proveedor, $compra, ['Authorization' => "Bearer {$token}"]];
    }

    public function test_pagar_registra_pago_parcial_y_descuenta_de_caja(): void
    {
        [, $compra, $headers] = $this->armarEscenario(1000, 0);

        $response = $this->withHeaders($headers)->postJson("/api/v1/deudas/{$compra->id}/pagar", [
            'monto' => 400, 'fecha' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(200);
        $fresca = $compra->fresh();
        $this->assertEquals(400, (float) $fresca->monto_pagado);
        $this->assertEquals('parcial', $fresca->estado_deuda);
        $this->assertEquals(600, (float) Turno::where('id_usuario', $compra->id_usuario)->value('efectivo_actual'));
    }

    // El caso que no tenía ningún rastro: pagar() ahora deja un movimiento de
    // caja, igual que ya hacía una compra pagada al contado.
    public function test_pagar_deja_un_movimiento_de_caja_con_el_nombre_del_proveedor(): void
    {
        [$proveedor, $compra, $headers] = $this->armarEscenario(1000, 0);

        $this->withHeaders($headers)->postJson("/api/v1/deudas/{$compra->id}/pagar", [
            'monto' => 400, 'fecha' => now()->format('Y-m-d'),
        ])->assertStatus(200);

        $this->assertDatabaseHas('movimientos_caja', [
            'tipo' => 'egreso', 'monto' => 400,
        ]);
        $movimiento = \App\Models\MovimientoCaja::where('monto', 400)->where('tipo', 'egreso')->firstOrFail();
        $this->assertStringContainsString($proveedor->persona, $movimiento->motivo);
        $this->assertStringContainsString((string) $compra->id, $movimiento->motivo);
    }

    public function test_pagar_no_permite_superar_el_saldo_pendiente(): void
    {
        [, $compra, $headers] = $this->armarEscenario(1000, 800);

        $response = $this->withHeaders($headers)->postJson("/api/v1/deudas/{$compra->id}/pagar", [
            'monto' => 500, 'fecha' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(200);
        $fresca = $compra->fresh();
        $this->assertEquals(1000, (float) $fresca->monto_pagado);
        $this->assertEquals('pagado', $fresca->estado_deuda);
    }

    public function test_pagar_rechaza_una_compra_ya_totalmente_pagada(): void
    {
        [, $compra, $headers] = $this->armarEscenario(1000, 1000);

        $response = $this->withHeaders($headers)->postJson("/api/v1/deudas/{$compra->id}/pagar", [
            'monto' => 100, 'fecha' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(400);
    }
}
