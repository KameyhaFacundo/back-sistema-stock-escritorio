<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProveedoresControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Proveedores ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);

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

    // Regresión: la columna cuit era NOT NULL aunque la validación ya la
    // trataba como opcional — crear un proveedor sin CUIT tiraba un 500 de
    // SQLite en vez de guardarse (ver migración make_cuit_nullable_in_proveedores_table).
    public function test_store_crea_proveedor_sin_cuit(): void
    {
        [, , $token] = $this->usuarioConPermisos(['create-proveedores']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/proveedores', ['persona' => 'Proveedor sin CUIT']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('proveedores', ['persona' => 'Proveedor sin CUIT', 'cuit' => null]);
    }

    public function test_store_puede_crear_dos_proveedores_sin_cuit(): void
    {
        [, , $token] = $this->usuarioConPermisos(['create-proveedores']);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/proveedores', ['persona' => 'Proveedor A']);
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/proveedores', ['persona' => 'Proveedor B']);

        $response->assertStatus(201);
    }
}
