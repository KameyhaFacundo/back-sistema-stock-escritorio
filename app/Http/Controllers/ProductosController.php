<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPlanLimits;
use App\Http\Requests\CreateProductoRequest;
use App\Http\Requests\UpdateProductoRequest;
use App\Models\ComboComponente;
use App\Models\HistorialPrecio;
use App\Models\LineaCompra;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Proveedor;
use App\Services\GeminiService;
use App\Services\StockService;
use App\Services\VarianteProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductosController extends Controller
{
    use ChecksPlanLimits;

    public function __construct(
        private StockService $stockService,
        private GeminiService $geminiService,
        private VarianteProductoService $varianteService,
    ) {}

    /**
     * Sucursal para la que se calcula el stock de esta request: la que venga
     * explícita por query param (para que un super-admin pueda mirar otra sin
     * cambiar su sucursal activa) o si no la del usuario autenticado.
     */
    private function sucursalDeRequest(Request $request): ?int
    {
        return $request->filled('id_sucursal')
            ? (int) $request->id_sucursal
            : auth()->user()?->id_sucursal;
    }

    /**
     * Adjunta stock/stock_minimo/stock_disponible (calculados para $idSucursal) a
     * cada producto — ya no son columnas ni accessors mágicos de Producto, viven
     * en producto_stock (ver Fase 1 del plan de multi-sucursal).
     */
    private function conStockDe(Producto $producto, ?int $idSucursal): array
    {
        $arr = $producto->toArray();
        $arr['stock']            = $idSucursal ? $producto->stockEnSucursal($idSucursal) : 0;
        $arr['stock_minimo']     = $idSucursal ? $producto->stockMinimoEnSucursal($idSucursal) : 5;
        $arr['stock_disponible'] = $idSucursal ? $producto->stockDisponibleEnSucursal($idSucursal) : 0;
        // Suma de stock en TODAS las sucursales (viene de withSum/loadSum('stocks', 'stock')
        // en cada método de abajo) — solo tiene sentido mostrarlo si la empresa tiene más
        // de una sucursal, eso lo decide el frontend con la lista que ya pide aparte.
        $arr['stock_total'] = (float) ($producto->stocks_sum_stock ?? $arr['stock']);

        // Cada variante (talle) tiene su propio stock — el padre no tiene ninguno
        // propio (ver el guard !tiene_variantes en store()/update()).
        if ($producto->tiene_variantes && $producto->relationLoaded('variantes')) {
            $arr['variantes'] = $producto->variantes
                ->map(fn ($v) => [
                    'id'           => $v->id,
                    'codigo'       => $v->codigo,
                    'id_talle'     => $v->id_talle,
                    'talle'        => $v->relationLoaded('talle') ? $v->talle?->valor : null,
                    'orden_talle'  => $v->relationLoaded('talle') ? $v->talle?->orden : null,
                    // Para "Comprar por curva" en Compras — cuántas unidades de
                    // este talle vienen en un bulto/curva, si se configuró.
                    'cantidad_curva' => $v->relationLoaded('talle') ? $v->talle?->cantidad_curva : null,
                    'stock'        => $idSucursal ? $v->stockEnSucursal($idSucursal) : 0,
                    'stock_minimo' => $idSucursal ? $v->stockMinimoEnSucursal($idSucursal) : 5,
                ])
                ->sortBy('orden_talle')
                ->values();
        }

        return $arr;
    }

    public function index(Request $request): JsonResponse
    {
        $empresaId  = auth()->user()->empresa_id;
        $idSucursal = $this->sucursalDeRequest($request);
        $version    = Cache::get("productos:list:version:{$empresaId}", 1);
        $paramsCacheables = ['search', 'id_categoria', 'estado', 'per_page', 'id_proveedor', 'stock_bajo', 'vencimiento_proximo', 'es_combo', 'tiene_variantes', 'codigo_exacto', 'sort', 'dir', 'page'];
        $cacheKey   = "productos:list:{$empresaId}:v{$version}:" . md5(json_encode($request->only($paramsCacheables) + ['id_sucursal' => $idSucursal]));

        $productos = Cache::remember($cacheKey, 300, function () use ($request, $idSucursal) {
            $query = Producto::with([
                'categoria', 'proveedor', 'proveedoresAlternativos',
                'stocks' => fn($q) => $q->where('id_sucursal', $idSucursal),
                'componentes.producto.stocks' => fn($q) => $q->where('id_sucursal', $idSucursal),
                'grupoTalle', 'talle',
                'variantes' => fn($q) => $q->where('estado', true)->with([
                    'talle',
                    'stocks' => fn($q2) => $q2->where('id_sucursal', $idSucursal),
                ]),
            ])
                // Una variante ya se ve anidada bajo su padre (arriba) — no debe
                // aparecer también como su propia fila suelta en el listado.
                ->whereNull('id_producto_padre')
                ->withSum('stocks', 'stock')
                ->withMax('historialPrecios as ultima_modificacion_precio', 'created_at');

            // codigo_exacto: 0-o-1 resultado por código/código de barras EXACTO —
            // para el escaneo del POS y el resolver de import de Movimientos, que
            // necesitan resolver un código real sin importar en qué página del
            // catálogo (ordenado alfabéticamente) caería con un LIKE + paginate
            // normal. Si viene, ignora "search" (son excluyentes).
            if ($request->filled('codigo_exacto')) {
                $codigo = $request->codigo_exacto;
                $query->where(function ($q) use ($codigo) {
                    $q->where('codigo', $codigo)->orWhere('codigo_barras', $codigo);
                });
            } elseif ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('producto', 'like', "%{$search}%")
                      ->orWhere('codigo', 'like', "%{$search}%")
                      ->orWhere('codigo_barras', 'like', "%{$search}%");
                });
            }

            if ($request->filled('id_categoria')) {
                $query->where('id_categoria', $request->id_categoria);
            }

            if ($request->has('estado') && $request->estado !== null) {
                $query->where('estado', $request->estado);
            }

            // Filtra por proveedor principal O alternativo — un producto tiene que
            // aparecer en la lista de "sus" productos aunque este proveedor no sea
            // el principal, si no queda invisible desde ese lado de la relación.
            if ($request->filled('id_proveedor')) {
                $idProveedor = $request->id_proveedor;
                $query->where(function ($q) use ($idProveedor) {
                    $q->where('id_proveedor', $idProveedor)
                      ->orWhereHas('proveedoresAlternativos', fn ($q2) => $q2->whereKey($idProveedor));
                });
            }

            if ($request->boolean('stock_bajo')) {
                $query->whereHas('stocks', fn($q) => $q->where('id_sucursal', $idSucursal)
                    ->whereColumn('stock', '<=', 'stock_minimo'));
            }

            // Mismo umbral que usaba el filtro del lado del cliente (30 días).
            if ($request->boolean('vencimiento_proximo')) {
                $query->whereNotNull('fecha_vencimiento')
                    ->whereDate('fecha_vencimiento', '<=', now()->addDays(30));
            }

            if ($request->has('es_combo')) {
                $query->where('es_combo', $request->boolean('es_combo'));
            }

            // Para "Comprar por curva" en Compras.jsx — el dropdown solo debe
            // ofrecer productos madre con talles (los únicos comprables por curva).
            if ($request->has('tiene_variantes')) {
                $query->where('tiene_variantes', $request->boolean('tiene_variantes'));
            }

            $sortable = ['nombre' => 'producto', 'precioFinal' => 'precio', 'reciente' => 'id'];
            $sortCol  = $sortable[$request->sort] ?? null;
            if ($sortCol) {
                $query->orderBy($sortCol, $request->dir === 'desc' ? 'desc' : 'asc');
            } elseif (in_array($request->sort, ['stock', 'stockTotal'], true)) {
                // Aproximado: ordena por el total entre sucursales (stocks_sum_stock,
                // ya viene del withSum de arriba), no por el stock de una sucursal
                // puntual — alcanza para el uso real (ordenar por "más/menos stock").
                $query->orderBy('stocks_sum_stock', $request->dir === 'desc' ? 'desc' : 'asc');
            } else {
                $query->orderBy('producto');
            }

            $paginado = $query->paginate($request->per_page ?? 15);
            $paginado->getCollection()->transform(fn($p) => $this->conStockDe($p, $idSucursal));

            return $paginado;
        });

        return response()->json([
            'success' => true,
            'data' => $productos,
        ]);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $idSucursal = $this->sucursalDeRequest($request);
        $producto = Producto::with([
            'categoria', 'proveedor', 'proveedoresAlternativos',
            'stocks' => fn($q) => $q->where('id_sucursal', $idSucursal),
            'componentes.producto.stocks' => fn($q) => $q->where('id_sucursal', $idSucursal),
            'grupoTalle', 'talle',
            'variantes' => fn($q) => $q->where('estado', true)->with([
                'talle',
                'stocks' => fn($q2) => $q2->where('id_sucursal', $idSucursal),
            ]),
        ])->withSum('stocks', 'stock')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->conStockDe($producto, $idSucursal),
        ]);
    }

    public function store(CreateProductoRequest $request): JsonResponse
    {
        $idSucursal = auth()->user()->id_sucursal;
        if (!$idSucursal) {
            return response()->json(['success' => false, 'message' => 'Tu usuario no tiene una sucursal asignada'], 422);
        }

        DB::beginTransaction();

        try {
            // Nota: no hay chequeo de límite de plan acá a propósito — este build es
            // de un solo comercio, sin planes pagos (ver ChecksPlanLimits::limitePlanExcedido(),
            // siempre null). Antes esto igual hacía un lockForUpdate() de la empresa +
            // un Producto::count() de TODA la tabla en cada alta — dos queries de más
            // por producto, ignoradas siempre, que se sentían fuerte en una importación
            // masiva de miles de filas (cada COUNT(*) más caro que el anterior a medida
            // que la tabla crecía durante el propio import).
            $validated = $request->validated();
            $componentes = $validated['componentes'] ?? null;
            $proveedoresAlternativos = $validated['proveedores_alternativos'] ?? null;
            $stockInicial = (float) ($validated['stock'] ?? 0);
            $stockMinimo  = (float) ($validated['stock_minimo'] ?? 5);
            unset($validated['componentes'], $validated['proveedores_alternativos'], $validated['stock'], $validated['stock_minimo']);

            $producto = Producto::create($validated);

            if ($proveedoresAlternativos !== null) {
                $error = $this->sincronizarProveedoresAlternativos($producto, $proveedoresAlternativos);
                if ($error) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => $error], 422);
                }
            }

            // El stock inicial solo tiene sentido para un producto normal — ni un
            // combo (se calcula de sus componentes) ni un producto madre con
            // variantes (el stock real vive en cada variante, no en el padre)
            // tienen fila propia de stock. Pasa por StockService::agregar() (no un
            // ProductoStock::create() directo) para que el stock inicial también
            // quede respaldado por un lote — si no, esta cantidad queda "fantasma"
            // en producto_stock.stock: la lista de Productos la muestra bien (lee
            // esa columna), pero StockService::disponible() (el que usan ventas y
            // movimientos para validar) suma de `lotes` y ve 0.
            if (!$producto->es_combo && !$producto->tiene_variantes) {
                $fila = $this->stockService->lockOrCrear($producto->id, $idSucursal, $producto->empresa_id);
                $fila->stock_minimo = $stockMinimo;
                $fila->save();
                if ($stockInicial > 0) {
                    $this->stockService->agregar($producto->id, $idSucursal, $stockInicial, $producto->empresa_id);
                }
            }

            if (!empty($validated['es_combo'])) {
                $error = $this->sincronizarComponentes($producto, $componentes ?? []);
                if ($error) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => $error], 422);
                }
            }

            if ($producto->tiene_variantes) {
                $this->varianteService->sincronizar($producto);
            }

            DB::commit();
            $this->clearListCache();

            // ?ligero=1: una importación masiva (ver Productos.jsx::confirmarImportacion())
            // crea cientos/miles de productos seguidos y no usa la respuesta de cada
            // uno más que para saber si salió bien — el reload de acá abajo (8
            // relaciones distintas) solo hace falta para refrescar la fila en la UI
            // normal de alta/edición, no en un loop en lote.
            if ($request->boolean('ligero')) {
                return response()->json(['success' => true, 'message' => 'Producto creado correctamente', 'data' => ['id' => $producto->id]], 201);
            }

            $producto->load([
                'categoria', 'proveedor', 'proveedoresAlternativos',
                'stocks' => fn($q) => $q->where('id_sucursal', $idSucursal),
                'componentes.producto.stocks' => fn($q) => $q->where('id_sucursal', $idSucursal),
                'grupoTalle', 'talle',
                'variantes' => fn($q) => $q->where('estado', true)->with([
                    'talle',
                    'stocks' => fn($q2) => $q2->where('id_sucursal', $idSucursal),
                ]),
            ]);
            $producto->loadSum('stocks', 'stock');

            return response()->json([
                'success' => true,
                'message' => 'Producto creado correctamente',
                'data' => $this->conStockDe($producto, $idSucursal),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el producto',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateProductoRequest $request, $id): JsonResponse
    {
        $idSucursal = auth()->user()->id_sucursal;
        if (!$idSucursal) {
            return response()->json(['success' => false, 'message' => 'Tu usuario no tiene una sucursal asignada'], 422);
        }

        DB::beginTransaction();

        try {
            $producto = Producto::findOrFail($id);
            $validated = $request->validated();
            $componentes = $validated['componentes'] ?? null;
            $proveedoresAlternativos = array_key_exists('proveedores_alternativos', $validated) ? $validated['proveedores_alternativos'] : null;
            $stockMinimo = $validated['stock_minimo'] ?? null;
            unset($validated['componentes'], $validated['proveedores_alternativos'], $validated['stock_minimo']);

            $precioAnterior = (float) $producto->precio;
            $costoAnterior  = (float) $producto->costo;
            $producto->update($validated);

            if ($proveedoresAlternativos !== null) {
                $error = $this->sincronizarProveedoresAlternativos($producto, $proveedoresAlternativos);
                if ($error) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => $error], 422);
                }
            }

            // La alerta de stock es por sucursal — solo tiene sentido para un
            // producto con stock propio, no para un combo ni un producto madre.
            if ($stockMinimo !== null && !$producto->es_combo && !$producto->tiene_variantes) {
                $fila = $this->stockService->lockOrCrear($producto->id, $idSucursal, $producto->empresa_id);
                $fila->stock_minimo = (float) $stockMinimo;
                $fila->save();
            }

            $esCombo = $validated['es_combo'] ?? $producto->es_combo;
            if ($esCombo && $componentes !== null) {
                $error = $this->sincronizarComponentes($producto, $componentes);
                if ($error) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => $error], 422);
                }
            } elseif (!$esCombo) {
                // Si se desactiva "es combo" (o nunca lo fue), no debe quedar
                // configuración de componentes huérfana.
                $producto->componentes()->delete();
            }

            if ($producto->tiene_variantes) {
                $this->varianteService->sincronizar($producto);
            }

            if (isset($validated['precio']) && (float) $validated['precio'] !== $precioAnterior) {
                HistorialPrecio::create([
                    'empresa_id'      => $producto->empresa_id,
                    'id_producto'     => $producto->id,
                    'campo'           => 'precio',
                    'precio_anterior' => $precioAnterior,
                    'precio_nuevo'    => (float) $validated['precio'],
                    'id_usuario'      => auth()->id(),
                ]);
            }

            if (isset($validated['costo']) && (float) $validated['costo'] !== $costoAnterior) {
                HistorialPrecio::create([
                    'empresa_id'      => $producto->empresa_id,
                    'id_producto'     => $producto->id,
                    'campo'           => 'costo',
                    'precio_anterior' => $costoAnterior,
                    'precio_nuevo'    => (float) $validated['costo'],
                    'id_usuario'      => auth()->id(),
                ]);
            }

            DB::commit();
            $this->clearListCache();

            // ?ligero=1: ver el mismo flag en store() — una actualización masiva
            // (ModalActualizarPrecios en Productos.jsx) llama a esto cientos de
            // veces seguidas y no usa la respuesta de cada una.
            if ($request->boolean('ligero')) {
                return response()->json(['success' => true, 'message' => 'Producto actualizado correctamente', 'data' => ['id' => $producto->id]]);
            }

            $producto->load([
                'categoria', 'proveedor', 'proveedoresAlternativos',
                'stocks' => fn($q) => $q->where('id_sucursal', $idSucursal),
                'componentes.producto.stocks' => fn($q) => $q->where('id_sucursal', $idSucursal),
                'grupoTalle', 'talle',
                'variantes' => fn($q) => $q->where('estado', true)->with([
                    'talle',
                    'stocks' => fn($q2) => $q2->where('id_sucursal', $idSucursal),
                ]),
            ]);
            $producto->loadSum('stocks', 'stock');

            return response()->json([
                'success' => true,
                'message' => 'Producto actualizado correctamente',
                'data' => $this->conStockDe($producto, $idSucursal),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el producto',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /productos/{id}/imagen — sube (o reemplaza) la foto del producto,
     * usada tanto en el listado interno como en el catálogo público.
     */
    public function subirImagen(Request $request, $id): JsonResponse
    {
        $empresa = auth()->user()->empresa;
        if ($empresa && ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'catalogo',
            'Las fotos de producto están disponibles desde el plan Pro. Actualizá tu plan para subir imágenes.'
        ))) {
            return $resp;
        }

        $producto = Producto::findOrFail($id);

        $request->validate([
            'imagen' => 'required|image|mimes:png,jpg,jpeg,webp|max:4096',
        ]);

        if ($producto->imagen_path) {
            Storage::disk('public')->delete($producto->imagen_path);
        }

        $path = $request->file('imagen')->store("productos/{$producto->empresa_id}", 'public');
        $producto->update(['imagen_path' => $path]);
        $this->clearListCache();

        return response()->json(['success' => true, 'data' => $producto->fresh()]);
    }

    /**
     * DELETE /productos/{id}/imagen — quita la foto (vuelve al placeholder).
     */
    public function eliminarImagen($id): JsonResponse
    {
        $producto = Producto::findOrFail($id);

        if ($producto->imagen_path) {
            Storage::disk('public')->delete($producto->imagen_path);
            $producto->update(['imagen_path' => null]);
        }
        $this->clearListCache();

        return response()->json(['success' => true, 'data' => $producto->fresh()]);
    }

    /**
     * POST /productos/{id}/imagen-ia — genera una foto de catálogo con IA
     * (Gemini) a partir del nombre/categoría del producto y la deja como su
     * imagen. Falla de forma controlada (503/502) si no hay clave configurada
     * o si el modelo no devolvió imagen — nunca rompe el producto existente.
     */
    public function generarImagenIa($id): JsonResponse
    {
        $empresa = auth()->user()->empresa;
        if ($empresa && ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'ia',
            'La generación de imágenes con IA está disponible en el plan IA. Actualizá tu plan para usarla.'
        ))) {
            return $resp;
        }

        $producto = Producto::with('categoria')->findOrFail($id);

        if (!config('services.gemini.api_key')) {
            return response()->json([
                'success' => false,
                'message' => 'La generación de imágenes con IA no está disponible: falta configurar la clave de Gemini.',
            ], 503);
        }

        $base64 = $this->geminiService->generarImagenProducto($producto->producto, $producto->categoria?->categoria);
        if (!$base64) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo generar la imagen con IA. Probá de nuevo o subí una foto manualmente.',
            ], 502);
        }

        if ($producto->imagen_path) {
            Storage::disk('public')->delete($producto->imagen_path);
        }

        $path = "productos/{$producto->empresa_id}/" . Str::uuid() . '.png';
        Storage::disk('public')->put($path, base64_decode($base64));
        $producto->update(['imagen_path' => $path]);
        $this->clearListCache();

        return response()->json(['success' => true, 'data' => $producto->fresh()]);
    }

    /**
     * POST /productos/{id}/imagen-combo-ia — solo para combos: compone una
     * foto usando como referencia las fotos reales que ya tengan cargadas sus
     * productos componentes (no genera desde cero como generarImagenIa).
     */
    public function generarImagenComboIa($id): JsonResponse
    {
        $empresa = auth()->user()->empresa;
        if ($empresa && ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'ia',
            'Componer imágenes con IA está disponible en el plan IA. Actualizá tu plan para usarla.'
        ))) {
            return $resp;
        }

        $combo = Producto::with('componentes.producto')->findOrFail($id);

        if (!$combo->es_combo) {
            return response()->json(['success' => false, 'message' => 'Este producto no es un combo.'], 422);
        }

        if (!config('services.gemini.api_key')) {
            return response()->json([
                'success' => false,
                'message' => 'La generación de imágenes con IA no está disponible: falta configurar la clave de Gemini.',
            ], 503);
        }

        $imagenesReferencia = [];
        foreach ($combo->componentes as $componente) {
            $path = $componente->producto?->imagen_path;
            if (!$path || !Storage::disk('public')->exists($path)) continue;
            $imagenesReferencia[] = [
                'data'     => base64_encode(Storage::disk('public')->get($path)),
                'mimeType' => Storage::disk('public')->mimeType($path) ?: 'image/png',
            ];
        }

        if (empty($imagenesReferencia)) {
            return response()->json([
                'success' => false,
                'message' => 'Ninguno de los productos de este combo tiene una foto propia todavía. Subí al menos una foto a alguno de sus componentes primero.',
            ], 422);
        }

        $base64 = $this->geminiService->componerImagenCombo($imagenesReferencia, $combo->producto);
        if (!$base64) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo componer la imagen con IA. Probá de nuevo o subí una foto manualmente.',
            ], 502);
        }

        if ($combo->imagen_path) {
            Storage::disk('public')->delete($combo->imagen_path);
        }

        $path = "productos/{$combo->empresa_id}/" . Str::uuid() . '.png';
        Storage::disk('public')->put($path, base64_decode($base64));
        $combo->update(['imagen_path' => $path]);
        $this->clearListCache();

        return response()->json(['success' => true, 'data' => $combo->fresh()]);
    }

    public function historialPrecios($id): JsonResponse
    {
        Producto::findOrFail($id);
        $historial = HistorialPrecio::with('usuario:nro_usu,des_usu')
            ->where('id_producto', $id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $historial,
        ]);
    }

    /**
     * A qué proveedores se le compró este producto y a qué precio cada vez —
     * lineas_compras ya guarda el precio_compra por línea (nunca se pisa
     * uno con otro), así que alcanza con traerlas con su compra/proveedor.
     * Solo compras confirmadas: una anulada o pendiente no refleja un costo
     * real pagado.
     */
    public function historialCompras($id): JsonResponse
    {
        Producto::findOrFail($id);

        $lineas = LineaCompra::with(['compra.proveedor:id,persona'])
            ->where('id_producto', $id)
            ->whereHas('compra', fn($q) => $q->where('estado', 'confirmada'))
            ->get()
            ->sortByDesc(fn($linea) => $linea->compra->fecha)
            ->values();

        return response()->json([
            'success' => true,
            'data'    => $lineas,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $producto = Producto::findOrFail($id);

        // Validar que no tenga líneas de compra/venta asociadas
        if ($producto->lineasCompras()->count() > 0 || $producto->lineasVentas()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el producto porque tiene transacciones asociadas',
            ], 400);
        }

        // Un producto madre no tiene transacciones propias (no se puede vender
        // directo — ver CreateVentaRequest), pero sus variantes sí pueden
        // tenerlas. SoftDeletes no cascadea solo: si ninguna variante tiene
        // transacciones, se borran junto con el padre; si alguna las tiene,
        // se bloquea todo el borrado en vez de dejar variantes huérfanas con
        // historial real.
        if ($producto->tiene_variantes) {
            $producto->loadMissing('variantes');
            foreach ($producto->variantes as $variante) {
                if ($variante->lineasCompras()->count() > 0 || $variante->lineasVentas()->count() > 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No se puede eliminar el producto porque una de sus variantes tiene transacciones asociadas',
                    ], 400);
                }
            }
            foreach ($producto->variantes as $variante) {
                $variante->delete();
            }
        }

        $producto->delete();

        return response()->json([
            'success' => true,
            'message' => 'Producto eliminado correctamente',
        ]);
    }

    /**
     * Reemplaza los componentes de un combo. Devuelve un mensaje de error (string)
     * si la lista es inválida, o null si quedó todo sincronizado correctamente.
     */
    private function sincronizarComponentes(Producto $combo, array $componentes): ?string
    {
        $idsComponentes = collect($componentes)->pluck('id_producto');

        if ($idsComponentes->contains($combo->id)) {
            return 'Un combo no puede tener a sí mismo como componente';
        }

        if ($idsComponentes->duplicates()->isNotEmpty()) {
            return 'No se puede repetir el mismo producto como componente';
        }

        $productosComponentes = Producto::where('empresa_id', $combo->empresa_id)
            ->whereIn('id', $idsComponentes)
            ->get()
            ->keyBy('id');

        foreach ($componentes as $componente) {
            $productoComponente = $productosComponentes[$componente['id_producto']] ?? null;
            if (!$productoComponente) {
                return 'Uno de los productos componentes no existe';
            }
            if ($productoComponente->es_combo) {
                return "'{$productoComponente->producto}' es un combo y no puede usarse como componente de otro combo";
            }
            // Un producto madre con variantes no tiene stock propio (vive en cada
            // talle) — como componente, el combo quedaría imposible de vender: el
            // chequeo de stock de VentaCreacionService siempre vería 0 disponible.
            if ($productoComponente->tiene_variantes) {
                return "'{$productoComponente->producto}' tiene variantes y no puede usarse como componente de un combo";
            }
        }

        $combo->componentes()->delete();
        foreach ($componentes as $componente) {
            ComboComponente::create([
                'empresa_id'  => $combo->empresa_id,
                'id_combo'    => $combo->id,
                'id_producto' => $componente['id_producto'],
                'cantidad'    => $componente['cantidad'],
            ]);
        }

        return null;
    }

    private function sincronizarProveedoresAlternativos(Producto $producto, array $alternativos): ?string
    {
        $ids = collect($alternativos)->pluck('id_proveedor');

        if ($producto->id_proveedor && $ids->contains($producto->id_proveedor)) {
            return 'El proveedor principal no puede repetirse como alternativo';
        }

        if ($ids->duplicates()->isNotEmpty()) {
            return 'No se puede repetir el mismo proveedor alternativo';
        }

        $existentes = Proveedor::where('empresa_id', $producto->empresa_id)->whereIn('id', $ids)->count();
        if ($existentes !== $ids->unique()->count()) {
            return 'Uno de los proveedores alternativos no existe';
        }

        // sync() inserta filas de la tabla pivote directo por query builder, sin
        // pasar por Producto::creating() — HasTenant no la alcanza, así que
        // empresa_id hay que mandarlo a mano (mismo motivo que ComboComponente::create()
        // de arriba lo hace explícito en vez de confiar en el trait).
        $sync = collect($alternativos)->mapWithKeys(fn ($a) => [
            $a['id_proveedor'] => [
                'empresa_id'       => $producto->empresa_id,
                'costo'            => $a['costo'] ?? null,
                'codigo_proveedor' => $a['codigo_proveedor'] ?? null,
            ],
        ]);
        $producto->proveedoresAlternativos()->sync($sync);

        return null;
    }

    private function clearListCache(): void
    {
        $empresaId = auth()->user()->empresa_id;
        $versionKey = "productos:list:version:{$empresaId}";
        Cache::forever($versionKey, Cache::get($versionKey, 1) + 1);
    }

    // GET /productos/etiquetas?ids=1,2,3 — devuelve los datos necesarios para
    // imprimir etiquetas de precio de los productos indicados.
    public function etiquetas(Request $request)
    {
        $empresa = auth()->user()->empresa;
        if ($empresa && ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'etiquetas',
            'La impresión de etiquetas está disponible desde el plan Pro. Actualizá tu plan para usarla.'
        ))) {
            return $resp;
        }

        $ids = explode(',', $request->query('ids', ''));
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) return response()->json([], 200);

        $productos = Producto::whereIn('id', $ids)
            ->where('empresa_id', auth()->user()->empresa_id)
            ->get();

        $result = $productos->map(function ($producto) {
            return [
                'id'      => $producto->id,
                'nombre'  => $producto->producto,
                'codigo'  => $producto->codigo ?? "PROD-{$producto->id}",
                'codigoBarras' => $producto->codigo_barras ?? '',
                'precio'  => (float) ($producto->precio ?? 0),
            ];
        });

        return response()->json($result);
    }
}
