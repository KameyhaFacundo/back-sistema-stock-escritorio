<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
