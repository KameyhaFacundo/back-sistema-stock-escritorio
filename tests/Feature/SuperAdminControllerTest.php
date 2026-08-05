<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SuperAdminControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(bool $superAdmin): array
    {
        $empresa = Empresa::create(['nombre' => 'Test SA ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'free']);

        $usuario = User::create([
            'des_usu'        => 'Usuario Test',
            'email'          => 'test' . uniqid() . '@test.com',
            'password'       => bcrypt('password'),
            'empresa_id'     => $empresa->id,
            'is_super_admin' => $superAdmin,
        ]);

        $token = JWTAuth::fromUser($usuario);

        return [$usuario, $empresa, $token];
    }

    public function test_empresas_rechaza_usuario_no_super_admin(): void
    {
        [, , $token] = $this->usuario(false);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/super-admin/empresas');

        $response->assertStatus(403);
    }

    public function test_empresas_permite_super_admin(): void
    {
        [, $empresaAjena, $token] = $this->usuario(true);
        Empresa::create(['nombre' => 'Otra Empresa Cualquiera', 'tipo' => 'almacen', 'plan' => 'pro']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/super-admin/empresas');

        $response->assertStatus(200);
        $nombres = collect($response->json('data'))->pluck('nombre');
        $this->assertTrue($nombres->contains('Otra Empresa Cualquiera'));
        $this->assertTrue($nombres->contains($empresaAjena->nombre));
    }

    public function test_registrar_pago_otorga_el_bono_de_facturas_del_plan(): void
    {
        [, $empresa, $token] = $this->usuario(true);
        $this->assertSame(0, $empresa->fresh()->facturas_disponibles);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/super-admin/empresas/{$empresa->id}/registrar-pago", [
                'plan' => 'esencial', 'ciclo' => 'mensual', 'monto' => 35000,
            ]);

        $response->assertStatus(200);
        $this->assertSame(100, $empresa->fresh()->facturas_disponibles);
        $this->assertSame(['esencial'], $empresa->fresh()->facturas_bono_otorgado);
    }

    public function test_registrar_pago_no_repite_el_bono_si_ya_esta_en_ese_plan(): void
    {
        [, $empresa, $token] = $this->usuario(true);
        $empresa->plan = 'esencial';
        $empresa->facturas_bono_otorgado = ['esencial'];
        $empresa->save();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/super-admin/empresas/{$empresa->id}/registrar-pago", [
                'plan' => 'esencial', 'ciclo' => 'mensual', 'monto' => 35000,
            ]);

        $response->assertStatus(200);
        $this->assertSame(0, $empresa->fresh()->facturas_disponibles);
    }
}
