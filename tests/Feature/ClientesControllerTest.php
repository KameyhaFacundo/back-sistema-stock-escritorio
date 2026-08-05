<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ClientesControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos, ?Empresa $empresa = null): array
    {
        $empresa = $empresa ?? Empresa::create(['nombre' => 'Test Clientes ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);

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

        $token = JWTAuth::fromUser($usuario);

        return [$usuario, $empresa, $token];
    }

    public function test_index_requiere_permiso(): void
    {
        [, , $token] = $this->usuarioConPermisos([]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/clientes');

        $response->assertStatus(403);
    }

    public function test_store_crea_cliente_con_condicion_iva(): void
    {
        [, $empresa, $token] = $this->usuarioConPermisos(['create-clientes']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/clientes', [
                'persona'       => 'Juan Pérez',
                'cuit'          => '20123456789',
                'condicion_iva' => 'Monotributista',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('clientes', [
            'persona'       => 'Juan Pérez',
            'empresa_id'    => $empresa->id,
            'condicion_iva' => 'Monotributista',
        ]);
    }

    public function test_store_rechaza_condicion_iva_invalida(): void
    {
        [, , $token] = $this->usuarioConPermisos(['create-clientes']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/clientes', [
                'persona'       => 'Juan Pérez',
                'condicion_iva' => 'Cualquier Cosa',
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rechaza_cuit_duplicado(): void
    {
        [, $empresa, $token] = $this->usuarioConPermisos(['create-clientes']);
        Cliente::create(['empresa_id' => $empresa->id, 'cuit' => '20123456789', 'persona' => 'Cliente Existente']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/clientes', [
                'persona' => 'Otro Cliente',
                'cuit'    => '20123456789',
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rechaza_email_duplicado_en_la_misma_empresa(): void
    {
        [, $empresa, $token] = $this->usuarioConPermisos(['create-clientes']);
        Cliente::create(['empresa_id' => $empresa->id, 'persona' => 'Cliente Existente', 'email' => 'repetido@test.com']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/clientes', [
                'persona' => 'Otro Cliente',
                'email'   => 'repetido@test.com',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_store_permite_el_mismo_email_en_otra_empresa(): void
    {
        $otraEmpresa = Empresa::create(['nombre' => 'Otra Empresa ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        Cliente::create(['empresa_id' => $otraEmpresa->id, 'persona' => 'Cliente de otra empresa', 'email' => 'repetido@test.com']);

        [, , $token] = $this->usuarioConPermisos(['create-clientes']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/clientes', [
                'persona' => 'Cliente Nuevo',
                'email'   => 'repetido@test.com',
            ]);

        $response->assertStatus(201);
    }
}
