<?php

namespace Tests\Feature;

use App\Jobs\EmitirFacturaJob;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\Venta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Solo cubre la guarda de idempotencia acá — el mapeo de la respuesta de
 * ArcaService a los campos de Factura (éxito/rechazo/excepción) ya está
 * cubierto por ArcaServiceTest (sin red) y FacturaControllerTest (dispatch
 * con Queue::fake()). No se mockea ArcaService con Mockery::mock('overload:...')
 * a propósito: ArcaService se instancia de verdad en varios otros tests de
 * la suite (FacturaControllerTest, NotaCreditoTest, ArcaServiceTest) que
 * corren en el mismo proceso — el overload de Mockery solo funciona si es
 * lo primero que toca esa clase en todo el proceso, así que en la práctica
 * choca con esos otros tests según el orden en que corran.
 */
class EmitirFacturaJobTest extends TestCase
{
    use DatabaseTransactions;

    public function test_no_hace_nada_si_la_factura_ya_no_esta_pendiente(): void
    {
        $empresa = Empresa::create([
            'nombre' => 'Test Job ' . uniqid(), 'tipo' => 'almacen', 'arca' => true,
            'cuit' => '20304050607', 'arca_cert' => 'cert', 'arca_key' => 'key', 'arca_punto_venta' => 1,
        ]);

        $venta = Venta::create([
            'empresa_id' => $empresa->id, 'id_usuario' => 1, 'estado' => 'confirmada',
            'fecha' => date('Y-m-d'), 'monto_total' => 1000,
        ]);

        $factura = Factura::create([
            'empresa_id' => $empresa->id, 'id_usuario' => 1, 'id_venta' => $venta->id,
            'tipo_comprobante' => 6, 'punto_venta' => 1, 'numero' => 5,
            'cae' => '12345678901234', 'vencimiento_cae' => '20260101',
            'fecha' => date('Ymd'), 'total' => 1000, 'neto' => 826.45, 'iva' => 173.55,
            'tipo_documento' => 99, 'numero_documento' => '0', 'estado' => 'emitida',
        ]);

        (new EmitirFacturaJob($factura->id))->handle();

        $factura->refresh();
        $this->assertEquals('emitida', $factura->estado);
        $this->assertEquals('12345678901234', $factura->cae);
    }
}
