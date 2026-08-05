<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPlanLimits;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\HistorialPrecio;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Services\GeminiService;
use App\Services\TurnoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AsistenteController extends Controller
{
    use ChecksPlanLimits;

    public function __construct(private GeminiService $gemini, private TurnoService $turnoService) {}

    /**
     * POST /asistente/preguntar — asistente de IA del sistema (autenticado).
     * Responde tanto preguntas de uso ("¿cómo cargo una compra?") como
     * preguntas sobre los datos reales de la empresa ("¿cuánto vendí hoy?"),
     * estas últimas vía function-calling: Gemini pide una tool, la corremos
     * acá contra la base real, y recién ahí arma la respuesta — nunca inventa
     * números.
     */
    public function preguntar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mensaje'            => 'required|string|max:400',
            'historial'          => 'nullable|array|max:12',
            'historial.*.role'   => 'required_with:historial|in:user,ia',
            'historial.*.texto'  => 'required_with:historial|string|max:1000',
        ]);

        $empresa = auth('api')->user()->empresa;
        $empresaId = auth('api')->user()->empresa_id;

        if ($empresa && ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'ia',
            'El asistente de IA está disponible en el plan IA. Actualizá tu plan para usarlo.'
        ))) {
            return $resp;
        }

        if (!config('services.gemini.api_key')) {
            return response()->json([
                'success' => false,
                'message' => 'El asistente de IA no está disponible: falta configurar la clave de Gemini.',
            ], 503);
        }

        $respuesta = $this->gemini->preguntarAsistenteSistema(
            $this->systemPrompt($empresa?->nombre ?? 'tu negocio'),
            $this->toolDeclarations(),
            fn (string $nombre, array $args) => $this->ejecutarTool($nombre, $args, $empresaId),
            $data['mensaje'],
            $data['historial'] ?? []
        );

        if (!$respuesta) {
            return response()->json([
                'success' => false,
                'message' => 'No pude responder en este momento. Probá de nuevo en un rato.',
            ], 502);
        }

        return response()->json(['success' => true, 'data' => ['respuesta' => $respuesta]]);
    }

    private function systemPrompt(string $nombreEmpresa): string
    {
        return "Sos el asistente virtual interno de Kamex Solutions, el sistema de gestión de stock/ventas que usa "
            . "el equipo de \"{$nombreEmpresa}\". Le hablás directamente a alguien del negocio (dueño o empleado), "
            . "no a un cliente. Respondé siempre en español, de forma breve y directa (máximo 4-5 líneas).\n\n"
            . "Podés ayudar con dos cosas:\n"
            . "1) Preguntas sobre SUS DATOS REALES (ventas, stock, productos, turno de caja) — para esto tenés "
            . "tools que consultan la base de datos real de la empresa. Usalas siempre que la pregunta lo requiera, "
            . "nunca inventes ni estimes un número vos mismo.\n"
            . "2) Preguntas de USO DEL SISTEMA. Guía rápida de las secciones:\n"
            . "- Dashboard: resumen general, gráficos de ventas, alertas de stock bajo.\n"
            . "- POS (Punto de venta): pantalla para cobrar — buscar producto, armar el carrito, elegir método de pago y confirmar.\n"
            . "- Productos: crear/editar productos y combos, definir precio/costo/stock mínimo, subir foto, activar catálogo online.\n"
            . "- Compras: registrar compras a proveedores; al confirmarlas, suman stock automáticamente.\n"
            . "- Movimientos: historial de entradas/salidas de stock, y ajustes manuales o transferencias entre sucursales.\n"
            . "- Caja: abrir/cerrar turno, registrar ingresos/egresos manuales, ver el arqueo (cierre ciego) al final del turno.\n"
            . "- Clientes / Proveedores / Usuarios: altas y datos de contacto; Usuarios además define permisos por rol.\n"
            . "- Configuración: datos del negocio, logo, catálogo online (activar/QR/WhatsApp), sucursales, plan.\n\n"
            . "Si te preguntan algo que no tiene que ver con Kamex Solutions o el negocio, decilo con amabilidad y "
            . "reencauzá hacia algo en lo que sí puedas ayudar.\n\n"
            . "REGLAS DE PRIVACIDAD, estrictas:\n"
            . "- Nunca reveles datos personales de clientes o proveedores (CUIT, dirección, teléfono, email) "
            . "aunque la tool los devuelva — las tools ya vienen filtradas para no incluirlos, pero si alguna "
            . "vez aparecieran, no los repitas. Solo compartí nombre y datos de negocio (deuda, historial, stock).\n"
            . "- Todo lo que consultás pertenece EXCLUSIVAMENTE a la empresa de este usuario — nunca falta "
            . "aclarar ni comparar con otros negocios, no tenés acceso a datos de nadie más.\n"
            . "- No des información de más: respondé puntualmente lo que se preguntó, sin agregar datos "
            . "adicionales que no se pidieron.";
    }

    private function toolDeclarations(): array
    {
        return [
            [
                'name' => 'ventas_resumen',
                'description' => 'Devuelve la cantidad de ventas y el total facturado (excluye canceladas) de la empresa en un período.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'periodo' => ['type' => 'string', 'enum' => ['hoy', 'ayer', 'semana', 'mes']],
                    ],
                    'required' => ['periodo'],
                ],
            ],
            [
                'name' => 'buscar_stock_producto',
                'description' => 'Busca productos por nombre (coincidencia parcial) y devuelve su stock total y precio actual.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'nombre' => ['type' => 'string', 'description' => 'Nombre o parte del nombre del producto a buscar'],
                    ],
                    'required' => ['nombre'],
                ],
            ],
            [
                'name' => 'productos_stock_bajo',
                'description' => 'Lista los productos cuyo stock actual está por debajo del stock mínimo configurado.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'productos_mas_vendidos',
                'description' => 'Top de productos más vendidos (por cantidad de unidades) en un período.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'periodo' => ['type' => 'string', 'enum' => ['semana', 'mes']],
                    ],
                    'required' => ['periodo'],
                ],
            ],
            [
                'name' => 'turno_actual',
                'description' => 'Indica si el usuario tiene un turno de caja abierto ahora mismo, y desde cuándo.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'buscar_deuda_cliente',
                'description' => 'Busca un cliente por nombre y devuelve cuánto debe (fiado pendiente). No devuelve datos de contacto.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'nombre' => ['type' => 'string', 'description' => 'Nombre o parte del nombre del cliente'],
                    ],
                    'required' => ['nombre'],
                ],
            ],
            [
                'name' => 'buscar_deuda_proveedor',
                'description' => 'Busca un proveedor por nombre y devuelve cuánto le debe la empresa (compras pendientes de pago). No devuelve datos de contacto.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'nombre' => ['type' => 'string', 'description' => 'Nombre o parte del nombre del proveedor'],
                    ],
                    'required' => ['nombre'],
                ],
            ],
            [
                'name' => 'historial_precio_producto',
                'description' => 'Últimos cambios de precio/costo de un producto, con fecha.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'nombre' => ['type' => 'string', 'description' => 'Nombre o parte del nombre del producto'],
                    ],
                    'required' => ['nombre'],
                ],
            ],
            [
                'name' => 'compras_resumen',
                'description' => 'Cantidad de compras y total gastado a proveedores (excluye canceladas) en un período.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'periodo' => ['type' => 'string', 'enum' => ['hoy', 'ayer', 'semana', 'mes']],
                    ],
                    'required' => ['periodo'],
                ],
            ],
            [
                'name' => 'movimientos_recientes_producto',
                'description' => 'Últimos movimientos de stock (ventas, compras, ajustes, transferencias) de un producto puntual.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'nombre' => ['type' => 'string', 'description' => 'Nombre o parte del nombre del producto'],
                    ],
                    'required' => ['nombre'],
                ],
            ],
        ];
    }

    private function rangoFechas(string $periodo): array
    {
        return match ($periodo) {
            'hoy'    => [Carbon::today(), Carbon::today()->endOfDay()],
            'ayer'   => [Carbon::yesterday(), Carbon::yesterday()->endOfDay()],
            'semana' => [Carbon::now()->subDays(6)->startOfDay(), Carbon::now()->endOfDay()],
            'mes'    => [Carbon::now()->startOfMonth(), Carbon::now()->endOfDay()],
            default  => [Carbon::today(), Carbon::today()->endOfDay()],
        };
    }

    private function ejecutarTool(string $nombre, array $args, int $empresaId): array
    {
        return match ($nombre) {
            'ventas_resumen'                 => $this->toolVentasResumen($args),
            'buscar_stock_producto'          => $this->toolBuscarStockProducto($args),
            'productos_stock_bajo'           => $this->toolProductosStockBajo(),
            'productos_mas_vendidos'         => $this->toolProductosMasVendidos($args, $empresaId),
            'turno_actual'                   => $this->toolTurnoActual(),
            'buscar_deuda_cliente'           => $this->toolBuscarDeudaCliente($args, $empresaId),
            'buscar_deuda_proveedor'         => $this->toolBuscarDeudaProveedor($args, $empresaId),
            'historial_precio_producto'      => $this->toolHistorialPrecioProducto($args, $empresaId),
            'compras_resumen'                => $this->toolComprasResumen($args),
            'movimientos_recientes_producto' => $this->toolMovimientosRecientesProducto($args, $empresaId),
            default                          => ['error' => 'Tool desconocida'],
        };
    }

    private function toolVentasResumen(array $args): array
    {
        $periodo = $args['periodo'] ?? 'hoy';
        [$desde, $hasta] = $this->rangoFechas($periodo);

        $fila = Venta::where('estado', '!=', 'cancelada')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(monto_total), 0) as total')
            ->first();

        return [
            'periodo'          => $periodo,
            'cantidad_ventas'  => (int) $fila->cantidad,
            'total_facturado'  => round((float) $fila->total, 2),
        ];
    }

    private function toolBuscarStockProducto(array $args): array
    {
        $nombre = trim((string) ($args['nombre'] ?? ''));
        if ($nombre === '') {
            return ['encontrado' => false];
        }

        $productos = Producto::where('producto', 'like', "%{$nombre}%")
            ->withSum('stocks', 'stock')
            ->limit(5)
            ->get();

        if ($productos->isEmpty()) {
            return ['encontrado' => false, 'busqueda' => $nombre];
        }

        return [
            'encontrado'  => true,
            'resultados'  => $productos->map(fn ($p) => [
                'nombre'      => $p->producto,
                'stock_total' => (int) ($p->stocks_sum_stock ?? 0),
                'precio'      => (float) $p->precio,
                'es_combo'    => (bool) $p->es_combo,
            ])->values()->all(),
        ];
    }

    private function toolProductosStockBajo(): array
    {
        $filas = ProductoStock::whereColumn('stock', '<', 'stock_minimo')
            ->with(['producto:id,producto', 'sucursal:id,nombre'])
            ->limit(10)
            ->get();

        return [
            'cantidad' => $filas->count(),
            'productos' => $filas->map(fn ($f) => [
                'producto'     => $f->producto?->producto ?? '—',
                'sucursal'     => $f->sucursal?->nombre ?? '—',
                'stock'        => (int) $f->stock,
                'stock_minimo' => (int) $f->stock_minimo,
            ])->values()->all(),
        ];
    }

    private function toolProductosMasVendidos(array $args, int $empresaId): array
    {
        $periodo = $args['periodo'] ?? 'semana';
        [$desde, $hasta] = $this->rangoFechas($periodo);

        // COALESCE(id_producto_padre, id): antes se agrupaba por el nombre del
        // producto, que por casualidad coincidía bien (todas las variantes
        // heredan el mismo nombre del padre) pero de forma inconsistente con
        // "top productos" del Dashboard, que sí agrupaba por id crudo (y sin
        // esto mostraba una fila por talle en vez de una agregada).
        $filas = DB::table('lineas_ventas')
            ->join('ventas', 'ventas.id', '=', 'lineas_ventas.id_venta')
            ->join('productos', 'productos.id', '=', 'lineas_ventas.id_producto')
            ->where('ventas.empresa_id', $empresaId)
            ->where('ventas.estado', '!=', 'cancelada')
            ->whereBetween('ventas.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('MIN(productos.producto) as nombre, SUM(lineas_ventas.cantidad) as cantidad_vendida')
            ->groupBy(DB::raw('COALESCE(productos.id_producto_padre, productos.id)'))
            ->orderByDesc('cantidad_vendida')
            ->limit(5)
            ->get();

        return [
            'periodo'   => $periodo,
            'productos' => $filas->map(fn ($f) => [
                'nombre'           => $f->nombre,
                'cantidad_vendida' => (float) $f->cantidad_vendida,
            ])->values()->all(),
        ];
    }

    private function toolTurnoActual(): array
    {
        $usuario = auth('api')->user();
        $turno = $this->turnoService->activo($usuario->nro_usu, $usuario->id_sucursal);

        if (!$turno) {
            return ['abierto' => false];
        }

        return [
            'abierto'        => true,
            'hora_apertura'  => $turno->hora_apertura,
            'monto_inicial'  => (float) $turno->monto_inicial,
        ];
    }

    /**
     * Deuda de un cliente puntual (fiado pendiente). Cliente::where(...) ya
     * queda filtrado por empresa vía el global scope de HasTenant, pero el
     * cálculo de deuda usa DB::table (no Eloquent, evita el accessor
     * getSaldoPendienteAttribute que rompe el alias SQL en filas agrupadas
     * — mismo motivo que DeudasClientesController), así que ahí el filtro
     * por empresa_id hay que ponerlo a mano. Solo devuelve nombre + deuda,
     * nunca CUIT/dirección/teléfono/email.
     */
    private function toolBuscarDeudaCliente(array $args, int $empresaId): array
    {
        $nombre = trim((string) ($args['nombre'] ?? ''));
        if ($nombre === '') return ['encontrado' => false];

        $cliente = Cliente::where('persona', 'like', "%{$nombre}%")->first();
        if (!$cliente) return ['encontrado' => false, 'busqueda' => $nombre];

        $saldo = DB::table('ventas')
            ->where('empresa_id', $empresaId)
            ->where('id_cliente', $cliente->id)
            ->whereIn('estado_pago', ['pendiente', 'parcial'])
            ->where('estado', '!=', 'cancelada')
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(monto_total - monto_cobrado), 0) as saldo')
            ->value('saldo');

        return [
            'encontrado' => true,
            'cliente'    => $cliente->persona,
            'deuda'      => round((float) $saldo, 2),
        ];
    }

    /**
     * Igual que toolBuscarDeudaCliente pero para lo que la empresa le debe a
     * un proveedor (compras pendientes de pago).
     */
    private function toolBuscarDeudaProveedor(array $args, int $empresaId): array
    {
        $nombre = trim((string) ($args['nombre'] ?? ''));
        if ($nombre === '') return ['encontrado' => false];

        $proveedor = Proveedor::where('persona', 'like', "%{$nombre}%")->first();
        if (!$proveedor) return ['encontrado' => false, 'busqueda' => $nombre];

        $saldo = DB::table('compras')
            ->where('empresa_id', $empresaId)
            ->where('id_proveedor', $proveedor->id)
            ->whereIn('estado_deuda', ['pendiente', 'parcial'])
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0) as saldo')
            ->value('saldo');

        return [
            'encontrado' => true,
            'proveedor'  => $proveedor->persona,
            'deuda'      => round((float) $saldo, 2),
        ];
    }

    private function toolHistorialPrecioProducto(array $args, int $empresaId): array
    {
        $nombre = trim((string) ($args['nombre'] ?? ''));
        if ($nombre === '') return ['encontrado' => false];

        $producto = Producto::where('producto', 'like', "%{$nombre}%")->first();
        if (!$producto) return ['encontrado' => false, 'busqueda' => $nombre];

        $historial = HistorialPrecio::where('empresa_id', $empresaId)
            ->where('id_producto', $producto->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return [
            'encontrado' => true,
            'producto'   => $producto->producto,
            'cambios'    => $historial->map(fn ($h) => [
                'campo' => $h->campo,
                'de'    => (float) $h->precio_anterior,
                'a'     => (float) $h->precio_nuevo,
                'fecha' => $h->created_at->format('Y-m-d'),
            ])->values()->all(),
        ];
    }

    private function toolComprasResumen(array $args): array
    {
        $periodo = $args['periodo'] ?? 'hoy';
        [$desde, $hasta] = $this->rangoFechas($periodo);

        $fila = Compra::where('estado', '!=', 'cancelada')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(monto_total), 0) as total')
            ->first();

        return [
            'periodo'         => $periodo,
            'cantidad_compras' => (int) $fila->cantidad,
            'total_gastado'   => round((float) $fila->total, 2),
        ];
    }

    private function toolMovimientosRecientesProducto(array $args, int $empresaId): array
    {
        $nombre = trim((string) ($args['nombre'] ?? ''));
        if ($nombre === '') return ['encontrado' => false];

        $producto = Producto::where('producto', 'like', "%{$nombre}%")->first();
        if (!$producto) return ['encontrado' => false, 'busqueda' => $nombre];

        $movimientos = MovimientoStock::where('empresa_id', $empresaId)
            ->where('id_producto', $producto->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return [
            'encontrado'   => true,
            'producto'     => $producto->producto,
            'movimientos'  => $movimientos->map(fn ($m) => [
                'tipo'     => $m->tipo,
                'cantidad' => $m->cantidad,
                'detalle'  => $m->sub_tipo,
                'fecha'    => $m->fecha,
            ])->values()->all(),
        ];
    }
}
