<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SistemaControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Sistema ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);

        $usuario = User::create([
            'des_usu'    => 'Usuario Test',
            'email'      => 'test' . uniqid() . '@test.com',
            'password'   => bcrypt('password'),
            'empresa_id' => $empresa->id,
        ]);

        if ($codigos) {
            $ids = Permiso::whereIn('codigo', $codigos)->pluck('id');
            $usuario->permisos()->attach($ids);
        }

        return [$usuario, $empresa, JWTAuth::fromUser($usuario)];
    }

    public function test_lan_ip_requiere_permiso(): void
    {
        [, , $token] = $this->usuarioConPermisos([]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/sistema/lan-ip');

        $response->assertStatus(403);
    }

    public function test_lan_ip_devuelve_puerto_y_clave_ip(): void
    {
        [, , $token] = $this->usuarioConPermisos(['view-configuracion']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/sistema/lan-ip');

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'data' => ['ip', 'puerto']]);
    }
}
