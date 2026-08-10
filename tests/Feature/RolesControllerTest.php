<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Los roles son un catálogo GLOBAL (sin empresa_id, compartido por toda la
 * plataforma, ver el comentario de checkSuperAdmin() en RolesController) —
 * el rol "admin" trae update-roles/delete-roles/create-roles por seed, así
 * que CUALQUIER dueño de empresa técnicamente tiene esos permisos. Sin el
 * gate de is_super_admin, cualquiera de ellos podría editar o borrar el
 * catálogo compartido y afectar a las demás empresas. No tenía ningún test.
 */
class RolesControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos, bool $superAdmin = false): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Roles ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $usuario = User::create([
            'des_usu' => 'Usuario Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id, 'is_super_admin' => $superAdmin,
        ]);
        $ids = Permiso::whereIn('codigo', $codigos)->pluck('id');
        $usuario->permisos()->attach($ids);
        $token = JWTAuth::fromUser($usuario);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_actualizar_rol_requiere_super_admin_aunque_tenga_el_permiso(): void
    {
        $headers = $this->usuarioConPermisos(['update-roles'], superAdmin: false);
        $rol = Rol::create(['codigo' => 'test-' . uniqid(), 'nombre' => 'Rol de prueba']);

        $response = $this->withHeaders($headers)->putJson("/api/v1/roles/{$rol->id}", [
            'codigo' => $rol->codigo, 'nombre' => 'Nombre pisado',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('Rol de prueba', $rol->fresh()->nombre);
    }

    public function test_super_admin_puede_actualizar_un_rol(): void
    {
        $headers = $this->usuarioConPermisos(['update-roles'], superAdmin: true);
        $rol = Rol::create(['codigo' => 'test-' . uniqid(), 'nombre' => 'Rol viejo']);

        $response = $this->withHeaders($headers)->putJson("/api/v1/roles/{$rol->id}", [
            'codigo' => $rol->codigo, 'nombre' => 'Rol nuevo',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Rol nuevo', $rol->fresh()->nombre);
    }

    public function test_eliminar_rol_requiere_super_admin(): void
    {
        $headers = $this->usuarioConPermisos(['delete-roles'], superAdmin: false);
        $rol = Rol::create(['codigo' => 'test-' . uniqid(), 'nombre' => 'Rol de prueba']);

        $response = $this->withHeaders($headers)->deleteJson("/api/v1/roles/{$rol->id}");

        $response->assertStatus(403);
        $this->assertNotNull($rol->fresh());
    }

    public function test_no_se_puede_eliminar_un_rol_con_usuarios_asignados(): void
    {
        $headers = $this->usuarioConPermisos(['delete-roles'], superAdmin: true);
        $empresa = Empresa::create(['nombre' => 'Otra Empresa ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $rol = Rol::create(['codigo' => 'test-' . uniqid(), 'nombre' => 'Rol con gente']);
        User::create([
            'des_usu' => 'Con Rol', 'email' => 'conrol' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id, 'id_rol' => $rol->id,
        ]);

        $response = $this->withHeaders($headers)->deleteJson("/api/v1/roles/{$rol->id}");

        $response->assertStatus(400);
        $this->assertNotNull($rol->fresh());
    }

    public function test_crear_rol_requiere_super_admin(): void
    {
        $headers = $this->usuarioConPermisos(['create-roles'], superAdmin: false);

        $response = $this->withHeaders($headers)->postJson('/api/v1/roles', [
            'codigo' => 'nuevo-' . uniqid(), 'nombre' => 'Rol nuevo',
        ]);

        $response->assertStatus(403);
    }
}
