<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\Sucursal;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CajaControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConCaja(array $codigos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Caja ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);

        $usuario = User::create([
            'des_usu'     => 'Cajero Test',
            'email'       => 'test' . uniqid() . '@test.com',
            'password'    => bcrypt('password'),
            'empresa_id'  => $empresa->id,
            'id_sucursal' => $sucursal->id,
        ]);

        if ($codigos) {
            $ids = Permiso::whereIn('codigo', $codigos)->pluck('id');
            $usuario->permisos()->attach($ids);
        }

        $token = JWTAuth::fromUser($usuario);

        return [$usuario, $empresa, $sucursal, $token];
    }

    public function test_abrir_requiere_permiso(): void
    {
        [, , , $token] = $this->usuarioConCaja([]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/caja/abrir', ['monto_inicial' => 1000]);

        $response->assertStatus(403);
    }

    public function test_abrir_sin_monto_inicial_falla(): void
    {
        [, , , $token] = $this->usuarioConCaja(['create-caja']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/caja/abrir', []);

        $response->assertStatus(422);
    }

    public function test_abrir_crea_turno_abierto(): void
    {
        [$usuario, $empresa, , $token] = $this->usuarioConCaja(['create-caja']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/caja/abrir', ['monto_inicial' => 5000]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('turnos', [
            'id_usuario' => $usuario->nro_usu,
            'estado'     => 'abierta',
            'empresa_id' => $empresa->id,
        ]);
    }

    public function test_abrir_cierra_turno_previo_abierto_del_mismo_usuario(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConCaja(['create-caja']);

        $turnoViejo = Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
            'id_usuario' => $usuario->nro_usu, 'estado' => 'abierta',
            'fecha' => now()->format('Y-m-d'), 'hora_apertura' => '08:00',
            'monto_inicial' => 1000, 'efectivo_actual' => 1000,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/caja/abrir', ['monto_inicial' => 2000]);

        $response->assertStatus(201);
        $this->assertEquals('cerrada', $turnoViejo->fresh()->estado);
    }
}
