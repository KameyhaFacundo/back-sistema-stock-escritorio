<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PagoVenta;
use App\Models\Permiso;
use App\Models\Sucursal;
use App\Models\Turno;
use App\Models\User;
use App\Models\Venta;
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

    public function test_movimiento_ingreso_efectivo_suma_al_efectivo_actual(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConCaja(['create-caja']);
        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
            'id_usuario' => $usuario->nro_usu, 'estado' => 'abierta',
            'fecha' => now()->format('Y-m-d'), 'hora_apertura' => '08:00',
            'monto_inicial' => 1000, 'efectivo_actual' => 1000,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/caja/movimiento', ['tipo' => 'ingreso', 'monto' => 500]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('movimientos_caja', ['tipo' => 'ingreso', 'metodo' => 'efectivo', 'monto' => 500]);
        $this->assertDatabaseHas('turnos', ['id_usuario' => $usuario->nro_usu, 'efectivo_actual' => 1500]);
    }

    public function test_movimiento_transferencia_no_toca_el_efectivo_actual(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConCaja(['create-caja']);
        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
            'id_usuario' => $usuario->nro_usu, 'estado' => 'abierta',
            'fecha' => now()->format('Y-m-d'), 'hora_apertura' => '08:00',
            'monto_inicial' => 1000, 'efectivo_actual' => 1000,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/caja/movimiento', ['tipo' => 'ingreso', 'metodo' => 'transferencia', 'monto' => 500]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('movimientos_caja', ['tipo' => 'ingreso', 'metodo' => 'transferencia', 'monto' => 500]);
        // El efectivo físico queda igual — una transferencia no es plata en la caja.
        $this->assertDatabaseHas('turnos', ['id_usuario' => $usuario->nro_usu, 'efectivo_actual' => 1000]);
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

    // Sin ver-montos-caja, los montos viajaban igual en el JSON aunque el
    // front los tapara con "•••••" — cualquiera con el Network tab abierto
    // los veía. Estos dos tests cubren que ahora el propio backend los oculta.
    public function test_turno_activo_sin_ver_montos_caja_oculta_los_montos(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConCaja(['list-caja', 'create-caja']);
        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
            'id_usuario' => $usuario->nro_usu, 'estado' => 'abierta',
            'fecha' => now()->format('Y-m-d'), 'hora_apertura' => '08:00',
            'monto_inicial' => 1000, 'efectivo_actual' => 1500, 'ventas_efectivo' => 500,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/caja/turno-activo');

        $response->assertOk();
        $response->assertJsonPath('data.monto_inicial', null);
        $response->assertJsonPath('data.efectivo_actual', null);
        $response->assertJsonPath('data.ventas_efectivo', null);
    }

    // El resumen de Caja (ver TabResumen en Caja.jsx) muestra ventas_tarjeta/
    // ventas_transferencia/ventas_qr/ventas_fiado, calculadas al vuelo sumando
    // las ventas del turno por metodo_pago (agregarTotalesPorMetodo). Esto
    // prueba que una venta con cada método aparece sumada en el lugar correcto.
    public function test_turno_activo_suma_ventas_por_metodo_de_pago(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConCaja(['list-caja', 'create-caja', 'ver-montos-caja']);
        $turno = Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
            'id_usuario' => $usuario->nro_usu, 'estado' => 'abierta',
            'fecha' => now()->format('Y-m-d'), 'hora_apertura' => '08:00',
            'monto_inicial' => 0, 'efectivo_actual' => 0,
        ]);

        foreach (['tarjeta' => 1000, 'transferencia' => 2000, 'qr' => 3000, 'fiado' => 4000] as $metodo => $monto) {
            Venta::create([
                'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
                'id_turno' => $turno->id, 'id_usuario' => $usuario->nro_usu,
                'estado' => 'confirmada', 'fecha' => now()->format('Y-m-d'), 'hora' => '10:00',
                'metodo_pago' => $metodo, 'monto_total' => $monto,
            ]);
        }
        // Anulada: no debe sumar (ver el where('estado', '!=', 'cancelada') en agregarTotalesPorMetodo).
        Venta::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
            'id_turno' => $turno->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'cancelada', 'fecha' => now()->format('Y-m-d'), 'hora' => '10:05',
            'metodo_pago' => 'tarjeta', 'monto_total' => 9999,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/caja/turno-activo');

        $response->assertOk();
        $response->assertJsonPath('data.ventas_tarjeta', 1000);
        $response->assertJsonPath('data.ventas_transferencia', 2000);
        $response->assertJsonPath('data.ventas_qr', 3000);
        $response->assertJsonPath('data.ventas_fiado', 4000);
    }

    // Una venta con "varios métodos" solo guarda metodo_pago = el PRIMERO
    // cargado — sin leer pagos_venta, esto le atribuiría el total ENTERO de
    // la venta dividida a ese único método (mismo bug que ya se arregló para
    // efectivo_actual). El desglose real tiene que repartirse bien acá.
    public function test_turno_activo_reparte_venta_dividida_por_cada_metodo_real(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConCaja(['list-caja', 'create-caja', 'ver-montos-caja']);
        $turno = Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
            'id_usuario' => $usuario->nro_usu, 'estado' => 'abierta',
            'fecha' => now()->format('Y-m-d'), 'hora_apertura' => '08:00',
            'monto_inicial' => 0, 'efectivo_actual' => 0,
        ]);

        $venta = Venta::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
            'id_turno' => $turno->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'confirmada', 'fecha' => now()->format('Y-m-d'), 'hora' => '10:00',
            // El primer pago cargado fue tarjeta — sin el fix, TODO el
            // monto_total ($5000) se le atribuiría a "tarjeta" en el resumen.
            'metodo_pago' => 'tarjeta', 'monto_total' => 5000,
        ]);
        PagoVenta::create(['id_venta' => $venta->id, 'metodo' => 'tarjeta', 'monto' => 2000]);
        PagoVenta::create(['id_venta' => $venta->id, 'metodo' => 'qr', 'monto' => 3000]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/caja/turno-activo');

        $response->assertOk();
        $response->assertJsonPath('data.ventas_tarjeta', 2000);
        $response->assertJsonPath('data.ventas_qr', 3000);
        $response->assertJsonPath('data.ventas_transferencia', 0);
    }

    public function test_turno_activo_con_ver_montos_caja_muestra_los_montos(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConCaja(['list-caja', 'create-caja', 'ver-montos-caja']);
        Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
            'id_usuario' => $usuario->nro_usu, 'estado' => 'abierta',
            'fecha' => now()->format('Y-m-d'), 'hora_apertura' => '08:00',
            'monto_inicial' => 1000, 'efectivo_actual' => 1500, 'ventas_efectivo' => 500,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/caja/turno-activo');

        $response->assertOk();
        $response->assertJsonPath('data.monto_inicial', '1000.00');
        $response->assertJsonPath('data.efectivo_actual', '1500.00');
        $response->assertJsonPath('data.ventas_efectivo', '500.00');
    }
}
