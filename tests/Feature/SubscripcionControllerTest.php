<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SubscripcionControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioAutenticado(): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Suscripcion ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'free']);
        $usuario = User::create([
            'des_usu' => 'Usuario Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id,
        ]);
        return [$usuario, JWTAuth::fromUser($usuario)];
    }

    public function test_crear_rechaza_plan_invalido(): void
    {
        [, $token] = $this->usuarioAutenticado();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/suscripcion/crear', ['plan' => 'no-existe', 'ciclo' => 'mensual']);

        $response->assertStatus(422);
    }

    public function test_crear_rechaza_ciclo_invalido(): void
    {
        [, $token] = $this->usuarioAutenticado();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/suscripcion/crear', ['plan' => 'pro', 'ciclo' => 'semanal']);

        $response->assertStatus(422);
    }

    public function test_crear_sin_token_de_mercadopago_configurado_falla_con_claridad(): void
    {
        [, $token] = $this->usuarioAutenticado();
        // Entorno de test no tiene MP_ACCESS_TOKEN — el controller debe fallar
        // de forma clara (500 con mensaje), no explotar tratando de llamar al SDK.
        unset($_ENV['MP_ACCESS_TOKEN'], $_SERVER['MP_ACCESS_TOKEN']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/suscripcion/crear', ['plan' => 'pro', 'ciclo' => 'mensual']);

        $response->assertStatus(500);
        $response->assertJson(['success' => false]);
    }

    public function test_crear_requiere_autenticacion(): void
    {
        $response = $this->postJson('/api/v1/suscripcion/crear', ['plan' => 'pro', 'ciclo' => 'mensual']);

        $response->assertStatus(401);
    }
}
