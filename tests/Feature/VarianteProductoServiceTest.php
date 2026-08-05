<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\GrupoTalle;
use App\Models\Producto;
use App\Models\Talle;
use App\Models\User;
use App\Services\VarianteProductoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Motor de variantes por talle (indumentaria) — sincronización aditiva,
 * propagación de un talle nuevo a productos ya existentes, y aislamiento
 * entre tenants. Corre contra la base real dentro de una transacción
 * (DatabaseTransactions), igual que StockServiceTest.
 */
class VarianteProductoServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function empresaIndumentaria(): Empresa
    {
        return Empresa::create(['nombre' => 'Test Indumentaria ' . uniqid(), 'tipo' => 'indument']);
    }

    private function usuario(Empresa $empresa): User
    {
        return User::create([
            'empresa_id' => $empresa->id, 'des_usu' => 'Test',
            'email' => 'test_' . uniqid() . '@test.com', 'password' => bcrypt('123456'),
            'is_super_admin' => false,
        ]);
    }

    public function test_sincronizar_genera_una_variante_por_cada_talle_del_grupo(): void
    {
        $empresa = $this->empresaIndumentaria();
        auth()->login($this->usuario($empresa));
        auth('api')->login(auth()->user());

        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'Remeras']);
        $grupo = GrupoTalle::create(['empresa_id' => $empresa->id, 'nombre' => 'Ropa']);
        Talle::create(['empresa_id' => $empresa->id, 'id_grupo_talle' => $grupo->id, 'valor' => 'S', 'orden' => 1]);
        Talle::create(['empresa_id' => $empresa->id, 'id_grupo_talle' => $grupo->id, 'valor' => 'M', 'orden' => 2]);
        Talle::create(['empresa_id' => $empresa->id, 'id_grupo_talle' => $grupo->id, 'valor' => 'L', 'orden' => 3]);

        $padre = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Remera básica', 'codigo' => 'REM-' . uniqid(),
            'precio' => 500, 'costo' => 0, 'estado' => true, 'id_categoria' => $categoria->id, 'tiene_variantes' => true, 'id_grupo_talle' => $grupo->id,
        ]);

        app(VarianteProductoService::class)->sincronizar($padre);

        $this->assertEquals(3, Producto::where('id_producto_padre', $padre->id)->count());
    }

    public function test_sincronizar_no_duplica_variantes_ya_generadas(): void
    {
        $empresa = $this->empresaIndumentaria();
        auth()->login($this->usuario($empresa));
        auth('api')->login(auth()->user());

        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'Remeras']);
        $grupo = GrupoTalle::create(['empresa_id' => $empresa->id, 'nombre' => 'Ropa']);
        Talle::create(['empresa_id' => $empresa->id, 'id_grupo_talle' => $grupo->id, 'valor' => 'M', 'orden' => 1]);

        $padre = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Remera básica', 'codigo' => 'REM-' . uniqid(),
            'precio' => 500, 'costo' => 0, 'estado' => true, 'id_categoria' => $categoria->id, 'tiene_variantes' => true, 'id_grupo_talle' => $grupo->id,
        ]);

        $service = app(VarianteProductoService::class);
        $service->sincronizar($padre);
        $service->sincronizar($padre->fresh());
        $service->sincronizar($padre->fresh());

        $this->assertEquals(1, Producto::where('id_producto_padre', $padre->id)->count());
    }

    public function test_sincronizar_propaga_cambio_de_precio_a_variantes_ya_generadas(): void
    {
        $empresa = $this->empresaIndumentaria();
        auth()->login($this->usuario($empresa));
        auth('api')->login(auth()->user());

        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'Remeras']);
        $grupo = GrupoTalle::create(['empresa_id' => $empresa->id, 'nombre' => 'Ropa']);
        Talle::create(['empresa_id' => $empresa->id, 'id_grupo_talle' => $grupo->id, 'valor' => 'M', 'orden' => 1]);

        $padre = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Remera básica', 'codigo' => 'REM-' . uniqid(),
            'precio' => 500, 'costo' => 0, 'estado' => true, 'id_categoria' => $categoria->id, 'tiene_variantes' => true, 'id_grupo_talle' => $grupo->id,
        ]);

        $service = app(VarianteProductoService::class);
        $service->sincronizar($padre);

        $padre->update(['precio' => 750]);
        $service->sincronizar($padre->fresh());

        $variante = Producto::where('id_producto_padre', $padre->id)->firstOrFail();
        $this->assertEquals(750, (float) $variante->precio);
    }

    public function test_propagar_nuevo_talle_genera_variante_en_productos_ya_existentes(): void
    {
        $empresa = $this->empresaIndumentaria();
        auth()->login($this->usuario($empresa));
        auth('api')->login(auth()->user());

        $categoria = Categoria::create(['empresa_id' => $empresa->id, 'categoria' => 'Remeras']);
        $grupo = GrupoTalle::create(['empresa_id' => $empresa->id, 'nombre' => 'Ropa']);
        Talle::create(['empresa_id' => $empresa->id, 'id_grupo_talle' => $grupo->id, 'valor' => 'M', 'orden' => 1]);

        $padre = Producto::create([
            'empresa_id' => $empresa->id, 'producto' => 'Remera básica', 'codigo' => 'REM-' . uniqid(),
            'precio' => 500, 'costo' => 0, 'estado' => true, 'id_categoria' => $categoria->id, 'tiene_variantes' => true, 'id_grupo_talle' => $grupo->id,
        ]);
        app(VarianteProductoService::class)->sincronizar($padre);
        $this->assertEquals(1, Producto::where('id_producto_padre', $padre->id)->count());

        // Se agrega un talle nuevo al grupo DESPUÉS de que el producto ya existía.
        $talleXL = Talle::create(['empresa_id' => $empresa->id, 'id_grupo_talle' => $grupo->id, 'valor' => 'XL', 'orden' => 2]);
        app(VarianteProductoService::class)->propagarNuevoTalle($talleXL);

        $this->assertEquals(2, Producto::where('id_producto_padre', $padre->id)->count());
    }

    public function test_sincronizar_no_hace_nada_si_el_grupo_de_talles_es_de_otra_empresa(): void
    {
        $empresaA = $this->empresaIndumentaria();
        $empresaB = $this->empresaIndumentaria();
        auth()->login($this->usuario($empresaA));
        auth('api')->login(auth()->user());

        $categoria = Categoria::create(['empresa_id' => $empresaA->id, 'categoria' => 'Remeras']);
        // Grupo de OTRA empresa — no debería poder generar variantes acá aunque
        // algún bug dejara este id_grupo_talle mal asignado.
        $grupoAjeno = GrupoTalle::create(['empresa_id' => $empresaB->id, 'nombre' => 'Ropa']);
        Talle::create(['empresa_id' => $empresaB->id, 'id_grupo_talle' => $grupoAjeno->id, 'valor' => 'M', 'orden' => 1]);

        $padre = Producto::create([
            'empresa_id' => $empresaA->id, 'producto' => 'Remera básica', 'codigo' => 'REM-' . uniqid(),
            'precio' => 500, 'costo' => 0, 'estado' => true, 'id_categoria' => $categoria->id, 'tiene_variantes' => true, 'id_grupo_talle' => $grupoAjeno->id,
        ]);

        app(VarianteProductoService::class)->sincronizar($padre);

        $this->assertEquals(0, Producto::where('id_producto_padre', $padre->id)->count());
    }
}
