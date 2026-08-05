<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductosControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function usuarioConPermisos(array $codigos, ?Empresa $empresa = null): array
    {
        $empresa = $empresa ?? Empresa::create(['nombre' => 'Test Productos ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);

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
            ->getJson('/api/v1/productos');

        $response->assertStatus(403);
    }

    public function test_index_no_devuelve_productos_de_otra_empresa(): void
    {
        $empresaB = Empresa::create(['nombre' => 'Empresa B ' . uniqid(), 'tipo' => 'almacen', 'plan' => 'pro']);
        $categoriaB = Categoria::create(['empresa_id' => $empresaB->id, 'categoria' => 'Cat B']);
        Producto::create([
            'empresa_id' => $empresaB->id, 'producto' => 'Producto de otra empresa',
            'precio' => 100, 'id_categoria' => $categoriaB->id,
        ]);

        [, , $token] = $this->usuarioConPermisos(['list-productos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/productos');

        $response->assertStatus(200);
        $nombres = collect($response->json('data.data') ?? $response->json('data'))->pluck('producto');
        $this->assertFalse($nombres->contains('Producto de otra empresa'));
    }

    public function test_store_crea_producto_valido(): void
    {
        [$usuario, $empresa, $token] = $this->usuarioConPermisos(['create-productos']);
        $sucursal = Sucursal::create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Central']);
        $usuario->update(['id_sucursal' => $sucursal->id]);
        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'General']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/productos', [
                'producto' => 'Coca Cola 500ml',
                'precio' => 1500,
                'id_categoria' => $categoria->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('productos', [
            'producto' => 'Coca Cola 500ml',
            'empresa_id' => $empresa->id,
        ]);
    }

    public function test_store_falla_sin_categoria(): void
    {
        [, , $token] = $this->usuarioConPermisos(['create-productos']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/productos', [
                'producto' => 'Producto sin categoría',
                'precio' => 100,
            ]);

        $response->assertStatus(422);
    }
}
