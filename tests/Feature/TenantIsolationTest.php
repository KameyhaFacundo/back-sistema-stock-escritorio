<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Factura;
use App\Models\Permiso;
use App\Models\PlantillaEtiqueta;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Regresión para el IDOR entre empresas que ya se parchó una vez a mano en
 * UsersController/PermisosController (ver comentarios ahí): User, Factura y
 * PlantillaEtiqueta no tienen HasTenant (o, en el caso de Factura, dependen
 * también del scope automático), así que cada endpoint que los toca por id
 * depende de que el controller se acuerde de filtrar por empresa_id. Estos
 * tests existen para que un cambio futuro que rompa ese filtrado falle acá
 * en vez de en producción.
 */
class TenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Tenant ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);

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

    private function usuarioDeOtraEmpresa(): User
    {
        $otraEmpresa = Empresa::create(['nombre' => 'Otra Empresa ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);

        return User::create([
            'des_usu'    => 'Usuario De Otra Empresa',
            'email'      => 'otro' . uniqid() . '@test.com',
            'password'   => bcrypt('password'),
            'empresa_id' => $otraEmpresa->id,
        ]);
    }

    // ── Users ──────────────────────────────────────────────────────────

    public function test_no_puede_ver_usuario_de_otra_empresa(): void
    {
        $victima = $this->usuarioDeOtraEmpresa();
        [, , $token] = $this->usuarioConPermisos(['view-usuarios']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/users/{$victima->nro_usu}");

        $response->assertStatus(404);
    }

    public function test_no_puede_editar_usuario_de_otra_empresa(): void
    {
        $victima = $this->usuarioDeOtraEmpresa();
        [, , $token] = $this->usuarioConPermisos(['update-usuarios']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/v1/users/{$victima->nro_usu}", ['des_usu' => 'Hackeado']);

        // update() envuelve todo en try/catch, así que el ModelNotFoundException
        // del findOrFail() scopeado termina devolviendo 500 en vez de 404 — lo que
        // importa acá es que el dato de la otra empresa no se haya tocado.
        $this->assertNotEquals(200, $response->status());
        $this->assertDatabaseHas('users', [
            'nro_usu' => $victima->nro_usu,
            'des_usu' => 'Usuario De Otra Empresa',
        ]);
    }

    public function test_no_puede_eliminar_usuario_de_otra_empresa(): void
    {
        $victima = $this->usuarioDeOtraEmpresa();
        [, , $token] = $this->usuarioConPermisos(['delete-usuarios']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->deleteJson("/api/v1/users/{$victima->nro_usu}");

        $response->assertStatus(404);
        $this->assertNotSoftDeleted('users', ['nro_usu' => $victima->nro_usu]);
    }

    // ── Permisos ───────────────────────────────────────────────────────

    public function test_no_puede_ver_permisos_de_usuario_de_otra_empresa(): void
    {
        $victima = $this->usuarioDeOtraEmpresa();
        [, , $token] = $this->usuarioConPermisos(['list-permisos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/permisos/usuario/{$victima->nro_usu}");

        $response->assertStatus(404);
    }

    public function test_no_puede_asignar_permisos_a_usuario_de_otra_empresa(): void
    {
        $victima = $this->usuarioDeOtraEmpresa();
        [, , $token] = $this->usuarioConPermisos(['assign-permisos']);
        $permisoId = Permiso::first()->id;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/permisos/usuario/{$victima->nro_usu}", ['permisos' => [$permisoId]]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('permisos_usuarios', ['id_usuario' => $victima->nro_usu, 'id_permiso' => $permisoId]);
    }

    public function test_no_puede_ver_historial_de_permisos_de_usuario_de_otra_empresa(): void
    {
        $victima = $this->usuarioDeOtraEmpresa();
        [, , $token] = $this->usuarioConPermisos(['list-permisos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/permisos/usuario/{$victima->nro_usu}/historial");

        $response->assertStatus(404);
    }

    // ── Plantillas de etiqueta ─────────────────────────────────────────

    public function test_no_puede_editar_plantilla_de_etiqueta_de_otra_empresa(): void
    {
        $otraEmpresa = Empresa::create(['nombre' => 'Otra Empresa ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $plantilla = PlantillaEtiqueta::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Original', 'config' => ['ancho' => 40]]);
        [, , $token] = $this->usuarioConPermisos(['view-etiquetas']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/v1/plantillas-etiqueta/{$plantilla->id}", ['nombre' => 'Hackeada']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('plantillas_etiqueta', ['id' => $plantilla->id, 'nombre' => 'Original']);
    }

    public function test_no_puede_eliminar_plantilla_de_etiqueta_de_otra_empresa(): void
    {
        $otraEmpresa = Empresa::create(['nombre' => 'Otra Empresa ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $plantilla = PlantillaEtiqueta::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Original', 'config' => ['ancho' => 40]]);
        [, , $token] = $this->usuarioConPermisos(['view-etiquetas']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->deleteJson("/api/v1/plantillas-etiqueta/{$plantilla->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('plantillas_etiqueta', ['id' => $plantilla->id]);
    }

    // ── Facturas ───────────────────────────────────────────────────────

    public function test_no_puede_ver_factura_de_otra_empresa(): void
    {
        $otraEmpresa = Empresa::create(['nombre' => 'Otra Empresa ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $otroUsuario = User::create([
            'des_usu' => 'Dueño otra empresa', 'email' => 'dueno' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $otraEmpresa->id,
        ]);
        $factura = Factura::create([
            'empresa_id' => $otraEmpresa->id,
            'id_usuario' => $otroUsuario->nro_usu,
            'tipo_comprobante' => 6,
            'punto_venta' => 1,
            'numero' => 1,
            'cae' => '12345678901234',
            'vencimiento_cae' => '20260101',
            'fecha' => '20260101',
            'total' => 1000,
        ]);
        [, , $token] = $this->usuarioConPermisos(['list-ventas']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/facturas/{$factura->id}");

        $response->assertStatus(404);
    }
}
