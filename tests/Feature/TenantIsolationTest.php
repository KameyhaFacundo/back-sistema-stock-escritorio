<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\Permiso;
use App\Models\PlantillaEtiqueta;
use App\Models\Proveedor;
use App\Models\User;
use App\Models\Venta;
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

    // Simula el caso documentado en HasTenant::bootHasTenant() — un usuario
    // autenticado sin empresa asignada (típicamente un super-admin fuera de
    // una impersonación). El global scope de HasTenant corta esto solo para
    // queries Eloquent; los controllers que usan DB::table a mano (Deudas*)
    // tienen que replicar el mismo "sin empresa = 0 filas" ellos mismos.
    private function usuarioSinEmpresa(array $codigos): array
    {
        $usuario = User::create([
            'des_usu'    => 'Super Admin Sin Empresa',
            'email'      => 'sinempresa' . uniqid() . '@test.com',
            'password'   => bcrypt('password'),
            'empresa_id' => null,
        ]);

        if ($codigos) {
            $ids = Permiso::whereIn('codigo', $codigos)->pluck('id');
            $usuario->permisos()->attach($ids);
        }

        $token = JWTAuth::fromUser($usuario);

        return [$usuario, $token];
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
        $venta = Venta::create([
            'empresa_id' => $otraEmpresa->id, 'id_usuario' => $otroUsuario->nro_usu,
            'estado' => 'confirmada', 'fecha' => '2026-01-01', 'monto_total' => 1000,
        ]);
        $factura = Factura::create([
            'empresa_id' => $otraEmpresa->id,
            'id_usuario' => $otroUsuario->nro_usu,
            'id_venta' => $venta->id,
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

    // ── Deudas (DB::table a mano, sin HasTenant) ──────────────────────────
    // Regresión: DeudasController y DeudasClientesController usaban
    // ->when($empresaId, ...) para el filtro de empresa en sus queries
    // DB::table — cuando $empresaId es null (usuario sin empresa), when()
    // omite el filtro entero en vez de no matchear nada, y el total/resumen
    // quedaba sumando la deuda de TODAS las empresas. Se corrigió a where()
    // (empresa_id = null → 0 filas, ver HasTenant::bootHasTenant()).

    public function test_usuario_sin_empresa_no_ve_deuda_de_clientes_de_otras_empresas(): void
    {
        $otraEmpresa = Empresa::create(['nombre' => 'Otra Empresa ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $otroUsuario = User::create([
            'des_usu' => 'Dueño otra empresa', 'email' => 'dueno' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $otraEmpresa->id,
        ]);
        Venta::create([
            'empresa_id' => $otraEmpresa->id, 'id_usuario' => $otroUsuario->nro_usu,
            'estado' => 'confirmada', 'estado_pago' => 'pendiente',
            'fecha' => '2026-01-01', 'monto_total' => 50000, 'monto_cobrado' => 0,
        ]);
        [, $token] = $this->usuarioSinEmpresa(['list-clientes']);

        $index = $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/v1/deudas-clientes');
        $index->assertOk();
        $this->assertEquals(0, $index->json('total_deuda'), 'No debe ver la deuda de otra empresa');

        $resumen = $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/v1/deudas-clientes/resumen');
        $resumen->assertOk();
        $this->assertCount(0, $resumen->json('data'), 'No debe listar clientes de otra empresa');
    }

    public function test_usuario_sin_empresa_no_ve_deuda_a_proveedores_de_otras_empresas(): void
    {
        $otraEmpresa = Empresa::create(['nombre' => 'Otra Empresa ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $otroUsuario = User::create([
            'des_usu' => 'Dueño otra empresa', 'email' => 'dueno' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $otraEmpresa->id,
        ]);
        $proveedor = Proveedor::create(['empresa_id' => $otraEmpresa->id, 'persona' => 'Proveedor Ajeno']);
        Compra::create([
            'empresa_id' => $otraEmpresa->id, 'id_usuario' => $otroUsuario->nro_usu, 'id_proveedor' => $proveedor->id,
            'estado' => 'confirmada', 'estado_deuda' => 'pendiente',
            'fecha' => '2026-01-01', 'monto_total' => 30000, 'monto_pagado' => 0,
        ]);
        [, $token] = $this->usuarioSinEmpresa(['list-proveedores']);

        $index = $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/v1/deudas');
        $index->assertOk();
        $this->assertEquals(0, $index->json('total_deuda'), 'No debe ver la deuda a proveedores de otra empresa');

        $resumen = $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/v1/deudas/resumen');
        $resumen->assertOk();
        $this->assertCount(0, $resumen->json('data'), 'No debe listar proveedores de otra empresa');
    }
}
