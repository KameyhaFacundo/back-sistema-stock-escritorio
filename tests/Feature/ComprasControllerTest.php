<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ComprasControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos): array
    {
        $empresa = Empresa::create(['nombre' => 'Test Compras ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);

        $usuario = User::create([
            'des_usu'     => 'Usuario Test',
            'email'       => 'test' . uniqid() . '@test.com',
            'password'    => bcrypt('password'),
            'empresa_id'  => $empresa->id,
            'id_sucursal' => $sucursal->id,
        ]);

        if ($codigos) {
            $ids = Permiso::whereIn('codigo', $codigos)->pluck('id');
            $usuario->permisos()->attach($ids);
        }

        return [$usuario, $empresa, $sucursal, JWTAuth::fromUser($usuario)];
    }

    private function crearCompra(Empresa $empresa, Sucursal $sucursal, User $usuario): Compra
    {
        $proveedor = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Proveedor Test', 'cuit' => '20304050607']);

        return Compra::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
            'id_proveedor' => $proveedor->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'confirmada', 'fecha' => now()->format('Y-m-d'), 'monto_total' => 1000, 'cuit' => '20304050607',
        ]);
    }

    // Regresión: compras.cuit seguía siendo NOT NULL después de hacer
    // proveedores.cuit opcional — crear una compra para un proveedor sin
    // CUIT tiraba un 500 genérico ("Error al crear la compra") en vez de
    // guardarse (ver migración make_cuit_nullable_in_compras_table).
    public function test_store_crea_compra_para_proveedor_sin_cuit(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['create-compras']);
        $proveedor = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Proveedor sin CUIT']);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $producto  = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto Test',
            'precio' => 100, 'id_categoria' => $categoria->id,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/compras', [
                'id_proveedor' => $proveedor->id,
                'fecha' => now()->format('Y-m-d'),
                'lineas' => [
                    ['id_producto' => $producto->id, 'precio_compra' => 50, 'cantidad' => 10],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('compras', ['id_proveedor' => $proveedor->id, 'cuit' => null]);
    }

    // El movimiento de caja de una compra al contado decía solo "Compra #ID" —
    // ahora suma el nombre del proveedor, para verlo de un vistazo en Caja sin
    // entrar a Compras (ver ajustarCajaCompra()).
    public function test_compra_confirmada_en_efectivo_deja_movimiento_con_nombre_del_proveedor(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['create-compras']);
        $proveedor = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Distribuidora Test']);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $producto  = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto Test',
            'precio' => 100, 'id_categoria' => $categoria->id,
        ]);
        \App\Models\Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'fecha' => now()->toDateString(), 'hora_apertura' => '09:00',
            'monto_inicial' => 1000, 'efectivo_actual' => 1000, 'ventas_efectivo' => 0,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/compras', [
                'id_proveedor' => $proveedor->id,
                'fecha' => now()->format('Y-m-d'),
                'metodo_pago' => 'efectivo',
                'lineas' => [
                    ['id_producto' => $producto->id, 'precio_compra' => 50, 'cantidad' => 10],
                ],
            ]);

        $response->assertStatus(201);
        $movimiento = \App\Models\MovimientoCaja::where('tipo', 'egreso')->where('monto', 500)->first();
        $this->assertNotNull($movimiento);
        $this->assertStringContainsString('Distribuidora Test', $movimiento->motivo);
    }

    // Pago dividido (ver 'pagos' en CreateCompraRequest) — parte ahora, el
    // resto queda de saldo en cuenta corriente con el proveedor.
    public function test_store_con_pago_dividido_deja_saldo_parcial(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['create-compras']);
        $proveedor = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Proveedor Test']);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $producto  = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto Test',
            'precio' => 100, 'id_categoria' => $categoria->id,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/compras', [
                'id_proveedor' => $proveedor->id,
                'fecha' => now()->format('Y-m-d'),
                'pagos' => [
                    ['metodo' => 'efectivo', 'monto' => 300],
                    ['metodo' => 'transferencia', 'monto' => 200],
                ],
                'lineas' => [
                    ['id_producto' => $producto->id, 'precio_compra' => 100, 'cantidad' => 10],
                ],
            ]);

        $response->assertStatus(201);
        $compra = Compra::find($response->json('data.id'));
        $this->assertEquals('parcial', $compra->estado_deuda);
        $this->assertEquals(500, (float) $compra->monto_pagado);
        $this->assertEquals(500, (float) $compra->monto_total - (float) $compra->monto_pagado);
        $this->assertDatabaseHas('pagos_proveedor', ['id_compra' => $compra->id, 'metodo_pago' => 'efectivo', 'monto' => 300]);
        $this->assertDatabaseHas('pagos_proveedor', ['id_compra' => $compra->id, 'metodo_pago' => 'transferencia', 'monto' => 200]);
    }

    // Con pago dividido, solo la porción REALMENTE en efectivo tiene que
    // entrar al arqueo — antes de este cambio, ajustarCajaCompra() confiaba
    // en compra->metodo_pago == "efectivo" para mover TODO monto_total, lo
    // cual hubiera inflado el cajón con la parte en transferencia también.
    public function test_pago_dividido_solo_la_porcion_efectivo_entra_al_arqueo(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['create-compras']);
        $proveedor = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Proveedor Test']);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $producto  = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto Test',
            'precio' => 100, 'id_categoria' => $categoria->id,
        ]);
        \App\Models\Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'fecha' => now()->toDateString(), 'hora_apertura' => '09:00',
            'monto_inicial' => 1000, 'efectivo_actual' => 1000, 'ventas_efectivo' => 0,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/compras', [
                'id_proveedor' => $proveedor->id,
                'fecha' => now()->format('Y-m-d'),
                'pagos' => [
                    ['metodo' => 'transferencia', 'monto' => 600],
                    ['metodo' => 'efectivo', 'monto' => 400],
                ],
                'lineas' => [
                    ['id_producto' => $producto->id, 'precio_compra' => 100, 'cantidad' => 10],
                ],
            ]);

        $response->assertStatus(201);
        $movimiento = \App\Models\MovimientoCaja::where('tipo', 'egreso')->first();
        $this->assertNotNull($movimiento);
        $this->assertEquals(400, (float) $movimiento->monto);
        $this->assertEquals(600, (float) \App\Models\Turno::first()->efectivo_actual);
    }

    // Anular una compra con pago dividido devuelve solo la parte que
    // realmente había salido en efectivo, no monto_total entero.
    public function test_anular_compra_con_pago_dividido_revierte_solo_la_porcion_efectivo(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['create-compras', 'change-status-compras']);
        $proveedor = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Proveedor Test']);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);
        $producto  = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Producto Test',
            'precio' => 100, 'id_categoria' => $categoria->id,
        ]);
        \App\Models\Turno::create([
            'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_usuario' => $usuario->nro_usu,
            'estado' => 'abierta', 'fecha' => now()->toDateString(), 'hora_apertura' => '09:00',
            'monto_inicial' => 1000, 'efectivo_actual' => 1000, 'ventas_efectivo' => 0,
        ]);
        $headers = ['Authorization' => "Bearer {$token}"];

        $creada = $this->withHeaders($headers)->postJson('/api/v1/compras', [
            'id_proveedor' => $proveedor->id,
            'fecha' => now()->format('Y-m-d'),
            'pagos' => [
                ['metodo' => 'efectivo', 'monto' => 400],
                ['metodo' => 'transferencia', 'monto' => 600],
            ],
            'lineas' => [
                ['id_producto' => $producto->id, 'precio_compra' => 100, 'cantidad' => 10],
            ],
        ]);
        $idCompra = $creada->json('data.id');
        $this->assertEquals(600, (float) \App\Models\Turno::first()->efectivo_actual); // 1000 - 400

        $response = $this->withHeaders($headers)->putJson("/api/v1/compras/{$idCompra}/change-status", ['estado' => 'cancelada']);

        $response->assertStatus(200);
        $this->assertEquals(1000, (float) \App\Models\Turno::first()->efectivo_actual); // vuelve el efectivo, no los 1000+600 de transferencia
    }

    public function test_subir_comprobante_requiere_permiso(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos([]);
        $compra = $this->crearCompra($empresa, $sucursal, $usuario);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/compras/{$compra->id}/comprobante", [
                'comprobante' => UploadedFile::fake()->image('ticket.jpg'),
            ]);

        $response->assertStatus(403);
    }

    public function test_subir_comprobante_guarda_el_archivo(): void
    {
        Storage::fake('public');
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['update-compras']);
        $compra = $this->crearCompra($empresa, $sucursal, $usuario);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/compras/{$compra->id}/comprobante", [
                'comprobante' => UploadedFile::fake()->image('ticket.jpg'),
            ]);

        $response->assertStatus(200);
        // Regresión: subirComprobante() devolvía $compra->fresh() sin relaciones,
        // así que el proveedor/usuario/líneas desaparecían de la respuesta y el
        // front (Compras.jsx, que usa esta respuesta para actualizar la fila en
        // la lista sin refetch) mostraba "Sin proveedor" hasta recargar.
        $response->assertJsonPath('data.proveedor.id', $compra->id_proveedor);
        $compra->refresh();
        $this->assertNotNull($compra->comprobante_path);
        Storage::disk('public')->assertExists($compra->comprobante_path);
    }

    public function test_subir_comprobante_acepta_pdf(): void
    {
        Storage::fake('public');
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['update-compras']);
        $compra = $this->crearCompra($empresa, $sucursal, $usuario);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/compras/{$compra->id}/comprobante", [
                'comprobante' => UploadedFile::fake()->create('factura.pdf', 200, 'application/pdf'),
            ]);

        $response->assertStatus(200);
    }

    public function test_eliminar_comprobante_borra_el_archivo(): void
    {
        Storage::fake('public');
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['update-compras']);
        $compra = $this->crearCompra($empresa, $sucursal, $usuario);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/compras/{$compra->id}/comprobante", [
                'comprobante' => UploadedFile::fake()->image('ticket.jpg'),
            ]);
        $path = $compra->refresh()->comprobante_path;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->deleteJson("/api/v1/compras/{$compra->id}/comprobante");

        $response->assertStatus(200);
        $response->assertJsonPath('data.proveedor.id', $compra->id_proveedor);
        $this->assertNull($compra->refresh()->comprobante_path);
        Storage::disk('public')->assertMissing($path);
    }

    // Regresión: Compras.jsx pedía sin per_page (default 200 de PAGE_SIZES.DEFAULT)
    // una sola vez y filtraba/paginaba TODO del lado del cliente sobre ese array
    // capado — con más de 200 compras en el historial, las más viejas quedaban
    // invisibles para cualquier búsqueda/filtro. Este test confirma que con
    // per_page chico se puede pedir cualquier página y el total real es correcto.
    public function test_index_pagina_correctamente_mas_alla_del_limite_viejo_de_200(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['list-compras']);
        $proveedor = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Proveedor Test']);
        for ($i = 0; $i < 3; $i++) {
            Compra::create([
                'empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id,
                'id_proveedor' => $proveedor->id, 'id_usuario' => $usuario->nro_usu,
                'estado' => 'confirmada', 'fecha' => now()->subDays($i)->format('Y-m-d'),
                'monto_total' => 100 * ($i + 1), 'cuit' => '0',
            ]);
        }

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/compras?per_page=2&page=2');

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('data.total'));
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_ordena_por_total(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['list-compras']);
        $proveedor = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Proveedor Test']);
        Compra::create(['empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_proveedor' => $proveedor->id, 'id_usuario' => $usuario->nro_usu, 'estado' => 'confirmada', 'fecha' => now()->format('Y-m-d'), 'monto_total' => 500, 'cuit' => '0']);
        Compra::create(['empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_proveedor' => $proveedor->id, 'id_usuario' => $usuario->nro_usu, 'estado' => 'confirmada', 'fecha' => now()->format('Y-m-d'), 'monto_total' => 100, 'cuit' => '0']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/compras?sort=total&dir=asc');

        $response->assertStatus(200);
        $montos = collect($response->json('data.data'))->pluck('monto_total');
        $this->assertEquals(['100.00', '500.00'], $montos->all());
    }

    public function test_index_ordena_por_proveedor(): void
    {
        [$usuario, $empresa, $sucursal, $token] = $this->usuarioConPermisos(['list-compras']);
        $provA = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Zeta Distribuciones']);
        $provB = Proveedor::create(['empresa_id' => $empresa->id, 'persona' => 'Alfa Insumos']);
        Compra::create(['empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_proveedor' => $provA->id, 'id_usuario' => $usuario->nro_usu, 'estado' => 'confirmada', 'fecha' => now()->format('Y-m-d'), 'monto_total' => 100, 'cuit' => '0']);
        Compra::create(['empresa_id' => $empresa->id, 'id_sucursal' => $sucursal->id, 'id_proveedor' => $provB->id, 'id_usuario' => $usuario->nro_usu, 'estado' => 'confirmada', 'fecha' => now()->format('Y-m-d'), 'monto_total' => 100, 'cuit' => '0']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/compras?sort=proveedor&dir=asc');

        $response->assertStatus(200);
        $nombres = collect($response->json('data.data'))->pluck('proveedor.persona');
        $this->assertEquals(['Alfa Insumos', 'Zeta Distribuciones'], $nombres->all());
    }
}
