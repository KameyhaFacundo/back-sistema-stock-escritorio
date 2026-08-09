<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Un ajuste manual de stock sin motivo es la tapadera perfecta para un
 * faltante: "cantidad" ya justifica el número que queda, pero nada explica
 * el porqué. create-movimientos ya está en el rol básico "Usuario" por
 * defecto (ver RolSeeder) — el motivo obligatorio es el único freno real.
 */
class MovimientosControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Movimientos ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);

        $usuario = User::create([
            'des_usu' => 'Usuario Test', 'email' => 'test' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
        ]);
        $ids = Permiso::whereIn('codigo', $codigos)->pluck('id');
        $usuario->permisos()->attach($ids);

        return [$usuario, JWTAuth::fromUser($usuario)];
    }

    public function test_rechaza_ajuste_sin_nota(): void
    {
        [, $token] = $this->usuarioConPermisos(['create-movimientos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/movimientos', [
                'producto' => 'Producto suelto', 'tipo' => 'ajuste', 'cantidad' => -1, 'fecha' => now()->format('Y-m-d'),
            ]);

        $response->assertStatus(422);
    }

    public function test_permite_ajuste_con_nota(): void
    {
        [, $token] = $this->usuarioConPermisos(['create-movimientos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/movimientos', [
                'producto' => 'Producto suelto', 'tipo' => 'ajuste', 'cantidad' => -1, 'fecha' => now()->format('Y-m-d'),
                'nota' => 'Rotura durante el reparto',
            ]);

        $response->assertStatus(201);
    }

    // Movimientos de venta/compra ya se justifican por su propio origen —
    // el motivo obligatorio es solo para 'ajuste', el manual sin respaldo.
    public function test_no_exige_nota_para_movimientos_de_venta(): void
    {
        [, $token] = $this->usuarioConPermisos(['create-movimientos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/movimientos', [
                'producto' => 'Producto suelto', 'tipo' => 'venta', 'cantidad' => -1, 'fecha' => now()->format('Y-m-d'),
            ]);

        $response->assertStatus(201);
    }

    // Una SUBA de stock no oculta ningún faltante — el front ya la deja
    // libre y opcional (MOTIVOS_BAJA es solo para bajas), así que acá no
    // hace falta exigir nada.
    public function test_no_exige_nota_para_ajuste_positivo(): void
    {
        [, $token] = $this->usuarioConPermisos(['create-movimientos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/movimientos', [
                'producto' => 'Producto suelto', 'tipo' => 'ajuste', 'cantidad' => 1, 'fecha' => now()->format('Y-m-d'),
            ]);

        $response->assertStatus(201);
    }
}
