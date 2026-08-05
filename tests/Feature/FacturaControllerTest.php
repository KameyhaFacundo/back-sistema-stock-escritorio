<?php

namespace Tests\Feature;

use App\Jobs\EmitirFacturaJob;
use App\Jobs\EmitirNotaCreditoJob;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\Permiso;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class FacturaControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConEmpresa(array $empresaAttrs): array
    {
        $empresa = Empresa::create(array_merge([
            'nombre' => 'Test Factura ' . uniqid(), 'tipo' => 'almacen',
        ], $empresaAttrs));

        $usuario = User::create([
            'des_usu'    => 'Usuario Test',
            'email'      => 'test' . uniqid() . '@test.com',
            'password'   => bcrypt('password'),
            'empresa_id' => $empresa->id,
        ]);

        // /facturas/emitir exige create-ventas (reusa permisos de ventas, ver routes/api.php)
        $usuario->permisos()->attach(Permiso::where('codigo', 'create-ventas')->pluck('id'));

        $token = JWTAuth::fromUser($usuario);

        return [$usuario, $empresa, $token];
    }

    private function payloadFactura(): array
    {
        return [
            'total' => 1000,
            'items' => [['precio' => 1000, 'cantidad' => 1]],
        ];
    }

    public function test_emitir_rechaza_facturacion_desactivada(): void
    {
        [, , $token] = $this->usuarioConEmpresa(['plan' => 'pro', 'arca' => false]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/facturas/emitir', $this->payloadFactura());

        $response->assertStatus(403);
    }

    public function test_emitir_en_modo_prueba_genera_factura_con_cae_ficticio(): void
    {
        [, , $token] = $this->usuarioConEmpresa(['plan' => 'pro', 'arca' => true, 'facturas_disponibles' => 10]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/facturas/emitir', $this->payloadFactura());

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'modo_prueba' => true]);
        $this->assertNotEmpty($response->json('data.cae'));
        $this->assertDatabaseHas('facturas', ['estado' => 'prueba', 'total' => 1000]);
    }

    /**
     * Empresa con ARCA configurado de verdad (cuit + cert + key + punto de
     * venta) — arcaConfigurada()=true, así que emitir() no debe llamar a
     * ARCA en este mismo request: guarda "pendiente" y encola el Job.
     */
    private function empresaConArcaConfigurado(array $extra = []): array
    {
        return $this->usuarioConEmpresa(array_merge([
            'plan' => 'pro', 'arca' => true, 'facturas_disponibles' => 10,
            'cuit' => '20304050607', 'arca_cert' => 'cert-de-prueba', 'arca_key' => 'key-de-prueba',
            'arca_punto_venta' => 1,
        ], $extra));
    }

    public function test_emitir_con_arca_configurado_queda_pendiente_y_encola_el_job(): void
    {
        Queue::fake();
        [, , $token] = $this->empresaConArcaConfigurado();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/facturas/emitir', $this->payloadFactura());

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'pendiente' => true]);
        $this->assertEquals('pendiente', $response->json('data.estado'));
        $this->assertNull($response->json('data.numero'));
        $this->assertNull($response->json('data.cae'));

        $this->assertDatabaseHas('facturas', ['estado' => 'pendiente', 'total' => 1000, 'numero' => null]);
        Queue::assertPushed(EmitirFacturaJob::class);
    }

    public function test_emitir_nota_credito_con_arca_configurado_queda_pendiente_y_encola_el_job(): void
    {
        Queue::fake();
        [$usuario, $empresa, $token] = $this->empresaConArcaConfigurado();

        $original = Factura::create([
            'empresa_id' => $empresa->id, 'id_usuario' => $usuario->nro_usu,
            'tipo_comprobante' => 6, 'punto_venta' => 1, 'numero' => 1,
            'cae' => '99999999999999', 'vencimiento_cae' => date('Ymd', strtotime('+10 days')),
            'fecha' => date('Ymd'), 'total' => 1000, 'neto' => 826.45, 'iva' => 173.55,
            'tipo_documento' => 99, 'numero_documento' => '0', 'estado' => 'prueba',
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/facturas/{$original->id}/nota-credito", ['monto' => 1000]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'pendiente' => true]);
        $this->assertEquals('pendiente', $response->json('data.estado'));
        $this->assertDatabaseHas('facturas', [
            'id_comprobante_asociado' => $original->id, 'estado' => 'pendiente', 'numero' => null,
        ]);
        Queue::assertPushed(EmitirNotaCreditoJob::class);
    }

    public function test_no_se_puede_acreditar_una_factura_todavia_pendiente(): void
    {
        [$usuario, $empresa, $token] = $this->empresaConArcaConfigurado();

        $pendiente = Factura::create([
            'empresa_id' => $empresa->id, 'id_usuario' => $usuario->nro_usu,
            'tipo_comprobante' => 6, 'punto_venta' => 1, 'numero' => null,
            'cae' => null, 'vencimiento_cae' => null,
            'fecha' => date('Ymd'), 'total' => 1000, 'neto' => 826.45, 'iva' => 173.55,
            'tipo_documento' => 99, 'numero_documento' => '0', 'estado' => 'pendiente',
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/facturas/{$pendiente->id}/nota-credito", ['monto' => 1000]);

        $response->assertStatus(422);
    }
}
