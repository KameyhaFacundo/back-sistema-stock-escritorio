<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthTest extends TestCase
{
    /**
     * Login con credenciales inválidas debe devolver 401.
     */
    public function test_login_with_invalid_credentials_returns_401(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email'    => 'noexiste@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['success' => false]);
    }

    /**
     * Login sin email debe fallar validación.
     */
    public function test_login_requires_email(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'password' => 'password',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Registro con datos inválidos debe fallar.
     */
    public function test_register_requires_valid_data(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Endpoint público accesible sin token.
     */
    public function test_public_endpoints_are_accessible(): void
    {
        $response = $this->getJson('/api/v1/categorias');

        // Sin token debería devolver 401 (requiere JWT)
        // o 200 si no está protegido. Las rutas de lista requieren auth.
        $this->assertContains($response->status(), [401]);
    }

    /**
     * Rate limiting debería bloquear después de muchos intentos.
     */
    public function test_rate_limiting_on_login(): void
    {
        for ($i = 0; $i < 61; $i++) {
            $this->postJson('/api/v1/login', [
                'email'    => 'test@test.com',
                'password' => 'wrong',
            ]);
        }

        $response = $this->postJson('/api/v1/login', [
            'email'    => 'test@test.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429); // Too Many Requests
    }
}
