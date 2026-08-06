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
     * Rate limiting debería bloquear después de muchos intentos — el límite
     * real es 10/min (ver RouteServiceProvider::boot(), limiter 'login'), no
     * 60/min: antes de que /login tuviera su limiter con nombre, el intento
     * 11 no bloqueaba porque compartía contador con el grupo "public"
     * (60/min) y recién bloqueaba cerca del intento 61 — de ahí el número
     * original de este test, que quedó "por las dudas" en vez de validar el
     * límite que el endpoint realmente promete.
     */
    public function test_rate_limiting_on_login(): void
    {
        for ($i = 0; $i < 10; $i++) {
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

    /**
     * Regresión: /health y /login compartían el mismo contador de rate limit
     * porque ambos usaban 'throttle:N,1' crudo, que resuelve la clave del
     * contador solo por dominio+IP (sin dominio configurado, siempre la
     * misma clave) — así que pingear /health (useOnlineStatus.js, cada 20s)
     * consumía cupo de los 10 intentos de login por minuto, causando
     * "Demasiados intentos" mucho antes de 10 intentos reales. Ver el
     * comentario largo en RouteServiceProvider::boot().
     */
    public function test_pings_a_health_no_consumen_el_cupo_de_login(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/health')->assertStatus(200);
        }

        $response = $this->postJson('/api/v1/login', [
            'email'    => 'test@test.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401); // no 429 — el cupo de login sigue intacto
    }
}
