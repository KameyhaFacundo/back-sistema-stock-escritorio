<?php

namespace Tests\Feature;

use App\Http\Controllers\FacturaController;
use App\Models\Cliente;
use App\Models\DevolucionVenta;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Services\ArcaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Nota de crédito contra una factura ya emitida — mapeo de tipo de
 * comprobante (A/B/C), acreditación parcial, y el límite de no acreditar
 * más de lo que la factura todavía tiene disponible.
 */
class NotaCreditoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mapeo_de_tipo_de_nota_de_credito_por_tipo_de_factura(): void
    {
        $this->assertEquals(ArcaService::TIPO_NOTA_CREDITO_A, ArcaService::tipoNotaCreditoPara(ArcaService::TIPO_FACTURA_A));
        $this->assertEquals(ArcaService::TIPO_NOTA_CREDITO_B, ArcaService::tipoNotaCreditoPara(ArcaService::TIPO_FACTURA_B));
        $this->assertEquals(ArcaService::TIPO_NOTA_CREDITO_C, ArcaService::tipoNotaCreditoPara(ArcaService::TIPO_FACTURA_C));
    }

    private function empresaFacturable(): Empresa
    {
        return Empresa::create(['nombre' => 'Test NC ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro', 'arca' => true, 'arca_punto_venta' => 1]);
    }

    private function facturaDePrueba(Empresa $empresa, int $tipoComprobante, float $total): Factura
    {
        return Factura::create([
            'empresa_id' => $empresa->id, 'id_venta' => null, 'id_usuario' => auth()->id(),
            'tipo_comprobante' => $tipoComprobante, 'punto_venta' => 1, 'numero' => random_int(1, 999999),
            'cae' => '99999999999999', 'vencimiento_cae' => date('Ymd', strtotime('+10 days')), 'fecha' => date('Ymd'),
            'total' => $total, 'neto' => round($total / 1.21, 2), 'iva' => round($total - $total / 1.21, 2),
            'tipo_documento' => 99, 'numero_documento' => '0', 'estado' => 'prueba',
        ]);
    }

    public function test_emite_nota_de_credito_parcial_contra_una_factura(): void
    {
        $empresa = $this->empresaFacturable();
        $usuario = User::create(['empresa_id' => $empresa->id, 'des_usu' => 'Test', 'email' => 'nc_' . uniqid() . '@test.com', 'password' => bcrypt('123456'), 'is_super_admin' => false]);
        auth()->login($usuario);
        auth('api')->login($usuario);

        $factura = $this->facturaDePrueba($empresa, ArcaService::TIPO_FACTURA_B, 1000);

        $controller = app(FacturaController::class);
        $req = Request::create("/facturas/{$factura->id}/nota-credito", 'POST', ['monto' => 300]);
        $resp = $controller->emitirNotaCredito($req, $factura->id);
        $data = json_decode($resp->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertEquals($factura->id, $data['data']['id_comprobante_asociado']);
        $this->assertEquals(ArcaService::TIPO_NOTA_CREDITO_B, (int) $data['data']['tipo_comprobante']);
        $this->assertNotEmpty($data['data']['cae']);
    }

    public function test_rechaza_nota_de_credito_que_excede_lo_disponible(): void
    {
        $empresa = $this->empresaFacturable();
        $usuario = User::create(['empresa_id' => $empresa->id, 'des_usu' => 'Test', 'email' => 'nc_' . uniqid() . '@test.com', 'password' => bcrypt('123456'), 'is_super_admin' => false]);
        auth()->login($usuario);
        auth('api')->login($usuario);

        $factura = $this->facturaDePrueba($empresa, ArcaService::TIPO_FACTURA_B, 500);
        $controller = app(FacturaController::class);

        // Primera NC consume todo el disponible.
        $req1 = Request::create("/facturas/{$factura->id}/nota-credito", 'POST', ['monto' => 500]);
        $controller->emitirNotaCredito($req1, $factura->id);

        // Segunda NC ya no tiene nada para acreditar.
        $req2 = Request::create("/facturas/{$factura->id}/nota-credito", 'POST', ['monto' => 1]);
        $resp2 = $controller->emitirNotaCredito($req2, $factura->id);
        $data2 = json_decode($resp2->getContent(), true);

        $this->assertFalse($data2['success']);
        $this->assertEquals(422, $resp2->getStatusCode());
    }

    public function test_show_de_la_factura_original_incluye_notas_de_credito_y_disponible(): void
    {
        $empresa = $this->empresaFacturable();
        $usuario = User::create(['empresa_id' => $empresa->id, 'des_usu' => 'Test', 'email' => 'nc_' . uniqid() . '@test.com', 'password' => bcrypt('123456'), 'is_super_admin' => false]);
        auth()->login($usuario);
        auth('api')->login($usuario);

        $factura = $this->facturaDePrueba($empresa, ArcaService::TIPO_FACTURA_B, 1000);
        $controller = app(FacturaController::class);
        $controller->emitirNotaCredito(Request::create("/facturas/{$factura->id}/nota-credito", 'POST', ['monto' => 300]), $factura->id);

        $resp = $controller->show($factura->id);
        $data = json_decode($resp->getContent(), true)['data'];

        $this->assertCount(1, $data['notas_credito']);
        $this->assertEquals(300, (float) $data['notas_credito'][0]['total']);
        $this->assertEquals(300, (float) $data['ya_acreditado']);
        $this->assertEquals(700, (float) $data['disponible']);
        $this->assertNull($data['comprobante_asociado']);
    }

    public function test_show_de_una_nota_de_credito_incluye_el_comprobante_asociado(): void
    {
        $empresa = $this->empresaFacturable();
        $usuario = User::create(['empresa_id' => $empresa->id, 'des_usu' => 'Test', 'email' => 'nc_' . uniqid() . '@test.com', 'password' => bcrypt('123456'), 'is_super_admin' => false]);
        auth()->login($usuario);
        auth('api')->login($usuario);

        $factura = $this->facturaDePrueba($empresa, ArcaService::TIPO_FACTURA_B, 1000);
        $controller = app(FacturaController::class);
        $respNc = $controller->emitirNotaCredito(Request::create("/facturas/{$factura->id}/nota-credito", 'POST', ['monto' => 300]), $factura->id);
        $idNc = json_decode($respNc->getContent(), true)['data']['id'];

        $resp = $controller->show($idNc);
        $data = json_decode($resp->getContent(), true)['data'];

        $this->assertEquals($factura->id, $data['comprobante_asociado']['id']);
        // No tiene sentido "disponible" para una NC misma.
        $this->assertArrayNotHasKey('disponible', $data);
    }

    public function test_nota_de_credito_vinculada_a_una_devolucion_muestra_su_detalle(): void
    {
        $empresa = $this->empresaFacturable();
        $usuario = User::create(['empresa_id' => $empresa->id, 'des_usu' => 'Test', 'email' => 'nc_' . uniqid() . '@test.com', 'password' => bcrypt('123456'), 'is_super_admin' => false]);
        auth()->login($usuario);
        auth('api')->login($usuario);

        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'persona' => 'Cliente Test', 'estado' => true, 'puntos' => 0]);
        $venta = Venta::create([
            'empresa_id' => $empresa->id, 'id_cliente' => $cliente->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'confirmada', 'fecha' => now()->toDateString(), 'monto_total' => 1000, 'cuit' => '0',
        ]);
        $devolucion = DevolucionVenta::create([
            'empresa_id' => $empresa->id, 'id_venta' => $venta->id, 'id_usuario' => $usuario->nro_usu,
            'motivo' => 'Producto con falla', 'monto_devuelto' => 300, 'monto_efectivo_devuelto' => 300,
        ]);

        $factura = $this->facturaDePrueba($empresa, ArcaService::TIPO_FACTURA_B, 1000);
        $factura->update(['id_venta' => $venta->id]);

        $controller = app(FacturaController::class);
        $respNc = $controller->emitirNotaCredito(
            Request::create("/facturas/{$factura->id}/nota-credito", 'POST', ['monto' => 300, 'id_devolucion_venta' => $devolucion->id]),
            $factura->id
        );
        $idNc = json_decode($respNc->getContent(), true)['data']['id'];

        $resp = $controller->show($idNc);
        $data = json_decode($resp->getContent(), true)['data'];

        $this->assertEquals($devolucion->id, $data['devolucion_venta']['id']);
        $this->assertEquals('Producto con falla', $data['devolucion_venta']['motivo']);
        $this->assertEquals(300, (float) $data['devolucion_venta']['monto_devuelto']);
        $this->assertEquals($usuario->nro_usu, $data['devolucion_venta']['usuario']['nro_usu']);
    }

    public function test_nota_de_credito_ignora_devolucion_de_otra_empresa(): void
    {
        $empresa = $this->empresaFacturable();
        $usuario = User::create(['empresa_id' => $empresa->id, 'des_usu' => 'Test', 'email' => 'nc_' . uniqid() . '@test.com', 'password' => bcrypt('123456'), 'is_super_admin' => false]);
        auth()->login($usuario);
        auth('api')->login($usuario);

        $otraEmpresa = $this->empresaFacturable();
        $otroUsuario = User::create(['empresa_id' => $otraEmpresa->id, 'des_usu' => 'Otro', 'email' => 'nc_' . uniqid() . '@test.com', 'password' => bcrypt('123456'), 'is_super_admin' => false]);
        $otroCliente = Cliente::create(['empresa_id' => $otraEmpresa->id, 'persona' => 'Otro Cliente', 'estado' => true, 'puntos' => 0]);
        $otraVenta = Venta::create([
            'empresa_id' => $otraEmpresa->id, 'id_cliente' => $otroCliente->id, 'id_usuario' => $otroUsuario->nro_usu,
            'estado' => 'confirmada', 'fecha' => now()->toDateString(), 'monto_total' => 1000, 'cuit' => '0',
        ]);
        $devolucionAjena = DevolucionVenta::create([
            'empresa_id' => $otraEmpresa->id, 'id_venta' => $otraVenta->id, 'id_usuario' => $otroUsuario->nro_usu,
            'motivo' => 'No es tuya', 'monto_devuelto' => 300, 'monto_efectivo_devuelto' => 300,
        ]);

        $factura = $this->facturaDePrueba($empresa, ArcaService::TIPO_FACTURA_B, 1000);
        $controller = app(FacturaController::class);
        $respNc = $controller->emitirNotaCredito(
            Request::create("/facturas/{$factura->id}/nota-credito", 'POST', ['monto' => 300, 'id_devolucion_venta' => $devolucionAjena->id]),
            $factura->id
        );
        $data = json_decode($respNc->getContent(), true)['data'];

        $this->assertNull($data['id_devolucion_venta']);
    }
}
