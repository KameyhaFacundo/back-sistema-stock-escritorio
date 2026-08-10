<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * El flujo de 2FA (activar/confirmar/login en dos pasos/desactivar) no tenía
 * ningún test — es justo la clase de bug donde una regresión silenciosa deja
 * la protección apagada, o peor, deja pasar el login sin pedir el segundo
 * factor a un usuario que sí lo tiene activado.
 */
class TwoFactorControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(): array
    {
        $empresa = Empresa::create(['nombre' => 'Test 2FA ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $usuario = User::create([
            'des_usu' => 'Usuario Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password123'), 'empresa_id' => $empresa->id,
        ]);
        $token = JWTAuth::fromUser($usuario);

        return [$usuario, ['Authorization' => "Bearer {$token}"]];
    }

    public function test_activar_genera_secret_pero_no_queda_activo_hasta_confirmar(): void
    {
        [$usuario, $headers] = $this->usuario();

        $response = $this->withHeaders($headers)->postJson('/api/v1/2fa/activar');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('otpauth_url'));
        $this->assertFalse($usuario->fresh()->two_factor_enabled);
        $this->assertNotNull($usuario->fresh()->two_factor_secret);
    }

    public function test_confirmar_con_codigo_incorrecto_no_activa(): void
    {
        [$usuario, $headers] = $this->usuario();
        $this->withHeaders($headers)->postJson('/api/v1/2fa/activar');

        $response = $this->withHeaders($headers)->postJson('/api/v1/2fa/confirmar', ['codigo' => '000000']);

        $response->assertStatus(422);
        $this->assertFalse($usuario->fresh()->two_factor_enabled);
    }

    public function test_confirmar_con_codigo_correcto_activa(): void
    {
        [$usuario, $headers] = $this->usuario();
        $this->withHeaders($headers)->postJson('/api/v1/2fa/activar');
        $secret = Crypt::decryptString($usuario->fresh()->two_factor_secret);
        $codigo = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->withHeaders($headers)->postJson('/api/v1/2fa/confirmar', ['codigo' => $codigo]);

        $response->assertStatus(200);
        $this->assertTrue($usuario->fresh()->two_factor_enabled);
        $this->assertNotNull($usuario->fresh()->two_factor_confirmed_at);
    }

    // El caso crítico: un usuario con 2FA activo NO debe recibir un JWT
    // directo en /login — debe quedar pendiente del segundo paso.
    public function test_login_con_2fa_activo_no_entrega_token_directo(): void
    {
        [$usuario] = $this->usuario();
        $secret = (new Google2FA())->generateSecretKey();
        $usuario->two_factor_secret = Crypt::encryptString($secret);
        $usuario->two_factor_enabled = true;
        $usuario->two_factor_confirmed_at = now();
        $usuario->save();

        $response = $this->postJson('/api/v1/login', ['email' => $usuario->email, 'password' => 'password123']);

        $response->assertStatus(200);
        $response->assertJsonFragment(['requiere_2fa' => true]);
        $this->assertArrayNotHasKey('access_token', $response->json());
        $this->assertNotEmpty($response->json('pending_token'));
    }

    public function test_login_verificar_2fa_con_codigo_incorrecto_no_entrega_token(): void
    {
        [$usuario] = $this->usuario();
        $secret = (new Google2FA())->generateSecretKey();
        $usuario->two_factor_secret = Crypt::encryptString($secret);
        $usuario->two_factor_enabled = true;
        $usuario->save();
        $pendingToken = 'test-pending-' . uniqid();
        Cache::put("2fa_pending:{$pendingToken}", $usuario->nro_usu, now()->addMinutes(5));

        $response = $this->postJson('/api/v1/login/2fa', ['pending_token' => $pendingToken, 'codigo' => '000000']);

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('access_token', $response->json());
    }

    public function test_login_verificar_2fa_con_codigo_correcto_entrega_token(): void
    {
        [$usuario] = $this->usuario();
        $secret = (new Google2FA())->generateSecretKey();
        $usuario->two_factor_secret = Crypt::encryptString($secret);
        $usuario->two_factor_enabled = true;
        $usuario->save();
        $pendingToken = 'test-pending-' . uniqid();
        Cache::put("2fa_pending:{$pendingToken}", $usuario->nro_usu, now()->addMinutes(5));
        $codigo = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->postJson('/api/v1/login/2fa', ['pending_token' => $pendingToken, 'codigo' => $codigo]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('access_token'));
    }

    public function test_desactivar_requiere_password_correcta(): void
    {
        [$usuario, $headers] = $this->usuario();
        $usuario->two_factor_secret = Crypt::encryptString((new Google2FA())->generateSecretKey());
        $usuario->two_factor_enabled = true;
        $usuario->save();

        $response = $this->withHeaders($headers)->postJson('/api/v1/2fa/desactivar', ['password' => 'incorrecta']);

        $response->assertStatus(422);
        $this->assertTrue($usuario->fresh()->two_factor_enabled);
    }

    public function test_desactivar_con_password_correcta_apaga_2fa(): void
    {
        [$usuario, $headers] = $this->usuario();
        $usuario->two_factor_secret = Crypt::encryptString((new Google2FA())->generateSecretKey());
        $usuario->two_factor_enabled = true;
        $usuario->save();

        $response = $this->withHeaders($headers)->postJson('/api/v1/2fa/desactivar', ['password' => 'password123']);

        $response->assertStatus(200);
        $this->assertFalse($usuario->fresh()->two_factor_enabled);
        $this->assertNull($usuario->fresh()->two_factor_secret);
    }
}
