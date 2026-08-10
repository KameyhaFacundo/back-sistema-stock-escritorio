<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\HistorialPermiso;
use App\Models\Permiso;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * User NO tiene HasTenant (sin scope automático por empresa_id) — permisosUsuario()/
 * agregarPermiso()/historialUsuario() lo scopean a mano, justo el gate que se
 * rompería en silencio si alguien lo saca "para simplificar". Sin test antes.
 */
class PermisosControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Permisos ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $usuario = User::create([
            'des_usu' => 'Usuario Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id,
        ]);
        $ids = Permiso::whereIn('codigo', $codigos)->pluck('id');
        $usuario->permisos()->attach($ids);
        $token = JWTAuth::fromUser($usuario);

        return [$empresa, ['Authorization' => "Bearer {$token}"]];
    }

    public function test_no_puede_ver_permisos_de_un_usuario_de_otra_empresa(): void
    {
        [, $headers] = $this->usuarioConPermisos(['list-permisos']);
        $otraEmpresa = Empresa::create(['nombre' => 'Otra Empresa ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $ajeno = User::create([
            'des_usu' => 'Usuario Ajeno', 'email' => 'ajeno' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $otraEmpresa->id,
        ]);

        $response = $this->withHeaders($headers)->getJson("/api/v1/permisos/usuario/{$ajeno->nro_usu}");

        $response->assertStatus(404);
    }

    public function test_no_puede_modificar_permisos_de_un_usuario_de_otra_empresa(): void
    {
        [, $headers] = $this->usuarioConPermisos(['assign-permisos']);
        $otraEmpresa = Empresa::create(['nombre' => 'Otra Empresa ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $ajeno = User::create([
            'des_usu' => 'Usuario Ajeno', 'email' => 'ajeno' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $otraEmpresa->id,
        ]);
        $permiso = Permiso::first();

        $response = $this->withHeaders($headers)->postJson("/api/v1/permisos/usuario/{$ajeno->nro_usu}", [
            'permisos' => [$permiso->id],
        ]);

        $response->assertStatus(404);
        $this->assertFalse($ajeno->fresh()->permisos()->where('permisos.id', $permiso->id)->exists());
    }

    public function test_agregar_permiso_actualiza_y_deja_registro_en_historial(): void
    {
        [$empresa, $headers] = $this->usuarioConPermisos(['assign-permisos']);
        $afectado = User::create([
            'des_usu' => 'Afectado', 'email' => 'afectado' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id,
        ]);
        $permiso = Permiso::first();

        $response = $this->withHeaders($headers)->postJson("/api/v1/permisos/usuario/{$afectado->nro_usu}", [
            'permisos' => [$permiso->id],
        ]);

        $response->assertStatus(200);
        $this->assertTrue($afectado->fresh()->permisos()->where('permisos.id', $permiso->id)->exists());
        $this->assertDatabaseHas('historial_permisos', [
            'id_usuario_afectado' => $afectado->nro_usu,
        ]);
        // permisos_agregados guarda CÓDIGOS (ver el diff de codigos en
        // PermisosController::agregarPermiso()), no ids.
        $registro = HistorialPermiso::where('id_usuario_afectado', $afectado->nro_usu)->firstOrFail();
        $this->assertContains($permiso->codigo, $registro->permisos_agregados);
    }

    public function test_mis_permisos_no_expone_los_de_otro_usuario(): void
    {
        [, $headers] = $this->usuarioConPermisos(['list-permisos', 'create-ventas']);

        $response = $this->withHeaders($headers)->getJson('/api/v1/permisos/mis-permisos');

        $response->assertStatus(200);
        // misPermisos() devuelve los objetos Permiso completos (obtenerTodosLosPermisos
        // → $this->permisos), no un array plano de códigos.
        $this->assertContains('create-ventas', collect($response->json('data'))->pluck('codigo'));
    }
}
