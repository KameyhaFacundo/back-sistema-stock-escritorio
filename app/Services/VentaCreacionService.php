<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\LineaVenta;
use App\Models\MovimientoPuntos;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Turno;
use App\Models\Venta;

class VentaCreacionService
{
    public function __construct(private StockService $stockService) {}

    /**
     * Crea una venta (+ líneas, decremento de stock, actualización de caja si es
     * efectivo) a partir de datos ya validados. Usado tanto por VentasController::store()
     * (venta manual, dentro de una request autenticada) como por el webhook de
     * confirmación de Point (request pública, sin usuario autenticado) — por eso
     * $empresaId se recibe explícito en vez de depender del scope automático de
     * HasTenant, que no se aplica sin un usuario autenticado en la request.
     *
     * @throws \RuntimeException si algo no pasa las validaciones (mensaje pensado
     *         para mostrarse tal cual al usuario).
     */
    public function crear(array $datos, int $empresaId, ?int $idTurno): Venta
    {
        $cliente = null;
        if (!empty($datos['id_cliente'])) {
            // lockForUpdate: el saldo de puntos se lee y decrementa en esta misma
            // transacción (canje) — evita que dos ventas concurrentes canjeen el
            // mismo punto dos veces.
            $cliente = Cliente::where('empresa_id', $empresaId)->lockForUpdate()->findOrFail($datos['id_cliente']);
            if (!$cliente->estado) {
                throw new \RuntimeException('El cliente no está activo');
            }
        }

        // La sucursal de la venta se resuelve siempre a partir del turno (nunca del
        // request directamente) — así se garantiza que coincide con la caja que de
        // verdad se está usando, sea venta manual o el webhook de Point.
        // lockForUpdate: efectivo_actual/ventas_efectivo se leen y se vuelven a
        // escribir más abajo (líneas 215-219) — sin el lock, una venta manual y la
        // confirmación async del webhook de Point sobre el mismo turno podían pisarse
        // el incremento una a la otra (lost update sobre el saldo de caja).
        $turnoActivo = $idTurno ? Turno::where('empresa_id', $empresaId)->lockForUpdate()->find($idTurno) : null;
        $idSucursal  = $turnoActivo?->id_sucursal;
        if (!$idSucursal) {
            throw new \RuntimeException('No se pudo determinar la sucursal de la venta (el turno no tiene una sucursal asignada)');
        }

        // Cargar todos los productos de las líneas con lock para evitar oversell
        // concurrente. Pueden ser productos normales o combos — un combo no tiene
        // stock propio, consume el de sus componentes (ver más abajo). Una línea
        // puede no tener id_producto — "monto libre" del POS (un cargo sin
        // producto real, ej. "Servicio de instalación") — esas no suman demanda
        // de stock ni se validan contra ningún producto.
        $ids = collect($datos['lineas'])->pluck('id_producto')->filter()->unique();
        $productosMap = Producto::where('empresa_id', $empresaId)
            ->whereIn('id', $ids)
            ->with('componentes')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($datos['lineas'] as $linea) {
            if (empty($linea['id_producto'])) continue;
            $producto = $productosMap[$linea['id_producto']] ?? null;
            if (!$producto) {
                throw new \RuntimeException('Producto no encontrado');
            }
            if (!$producto->estado) {
                throw new \RuntimeException("El producto '{$producto->producto}' no está activo");
            }
            if ($producto->es_combo && $producto->componentes->isEmpty()) {
                throw new \RuntimeException("El combo '{$producto->producto}' no tiene productos configurados");
            }
            // Un producto madre con variantes no tiene stock propio (vive en cada
            // variante) — se vende la variante puntual (talle), nunca el padre.
            if ($producto->tiene_variantes) {
                throw new \RuntimeException("'{$producto->producto}' tiene variantes — vendé el talle puntual, no el producto general");
            }
        }

        // Expandir cada línea a demanda real de stock por producto base: una línea
        // de combo consume stock de sus componentes, no de sí misma. Se acumula acá
        // (no se valida línea por línea) para que dos líneas que compartan un mismo
        // componente — un combo y una venta directa de esa misma bebida, por ejemplo —
        // se validen juntas contra el stock real, no cada una por separado.
        $demandaBase = [];
        foreach ($datos['lineas'] as $linea) {
            if (empty($linea['id_producto'])) continue;
            $producto = $productosMap[$linea['id_producto']];
            if ($producto->es_combo) {
                foreach ($producto->componentes as $comp) {
                    $demandaBase[$comp->id_producto] = ($demandaBase[$comp->id_producto] ?? 0) + $comp->cantidad * $linea['cantidad'];
                }
            } else {
                $demandaBase[$producto->id] = ($demandaBase[$producto->id] ?? 0) + $linea['cantidad'];
            }
        }

        // Los componentes de un combo pueden no estar ya cargados en $productosMap
        // (si nadie vendió ese producto suelto en el mismo carrito) — completar con lock.
        $idsFaltantes = collect(array_keys($demandaBase))->diff($productosMap->keys());
        $productosBase = $productosMap->union(
            $idsFaltantes->isEmpty()
                ? collect()
                : Producto::where('empresa_id', $empresaId)->whereIn('id', $idsFaltantes)->lockForUpdate()->get()->keyBy('id')
        );

        // Se bloquea (y valida) la fila de producto_stock de CADA producto base antes
        // de descontar ninguna — así dos líneas que comparten un mismo componente se
        // validan juntas contra el stock real de esta sucursal, no cada una por separado.
        // La validación mira disponible() (suma de lotes), la misma fuente que después
        // usa StockService::restar() para descontar — así el chequeo temprano y el
        // descuento real nunca pueden quedar en desacuerdo entre sí.
        // Se guardan la fila ya bloqueada y el disponible() ya calculado de cada
        // producto — el loop de más abajo que de verdad descuenta stock (restar())
        // los reutiliza en vez de repetir el mismo SELECT...FOR UPDATE y SUM(lotes)
        // por segunda vez para cada producto, en la venta (la operación más
        // frecuente de todo el sistema).
        $filasBloqueadas = [];
        $disponiblePorProducto = [];
        foreach ($demandaBase as $idProducto => $cantidadRequerida) {
            $productoBase = $productosBase[$idProducto] ?? null;
            if (!$productoBase) {
                throw new \RuntimeException('Producto no encontrado');
            }
            $filasBloqueadas[$idProducto] = $this->stockService->lockOrCrear($idProducto, $idSucursal, $empresaId);
            $disponible = $this->stockService->disponible($idProducto, $idSucursal);
            if ($disponible < $cantidadRequerida) {
                throw new \RuntimeException("Stock insuficiente para '{$productoBase->producto}'. Disponible: {$disponible}");
            }
            $disponiblePorProducto[$idProducto] = $disponible;
        }

        $montoTotal = collect($datos['lineas'])->sum(fn($l) => $l['precio_venta'] * $l['cantidad']);

        // Descuento/recargo sobre el subtotal — ver ajuste.activo en el POS del front.
        $ajuste = $datos['ajuste'] ?? null;
        if (!empty($ajuste) && !empty($ajuste['activo'] ?? true)) {
            $valorAjuste = (float) ($ajuste['valor'] ?? 0);
            $montoAjuste = ($ajuste['calculo'] ?? 'porcentaje') === 'porcentaje'
                ? $montoTotal * ($valorAjuste / 100)
                : $valorAjuste;
            $montoTotal = ($ajuste['tipo'] ?? 'descuento') === 'descuento'
                ? max(0, $montoTotal - $montoAjuste)
                : $montoTotal + $montoAjuste;
        }

        // Canje de puntos por descuento — separado del ajuste manual para que el
        // historial de puntos quede claro (no se mezcla con un descuento a mano).
        $puntosCanjeados = (int) ($datos['puntos_canjeados'] ?? 0);
        $empresa = null;
        if ($puntosCanjeados > 0) {
            if (!$cliente) {
                throw new \RuntimeException('Hace falta un cliente para canjear puntos');
            }
            if ($cliente->puntos < $puntosCanjeados) {
                throw new \RuntimeException("El cliente solo tiene {$cliente->puntos} puntos disponibles");
            }
            $empresa = Empresa::find($empresaId);
            $valorPunto = (float) ($empresa?->puntos_valor_pesos ?? 0);
            $montoTotal = max(0, $montoTotal - ($puntosCanjeados * $valorPunto));
        }

        $metodoPago   = $datos['metodo_pago'] ?? 'efectivo';
        $pagos        = $datos['pagos'] ?? null;
        $esFiado      = $metodoPago === 'fiado';
        $estadoPago   = $esFiado ? 'pendiente' : 'pagado';
        $montoCobrado = $esFiado ? 0 : $montoTotal;

        $venta = Venta::create([
            'empresa_id'    => $empresaId,
            'id_sucursal'   => $idSucursal,
            'numero_ticket' => $datos['numero_ticket'] ?? null,
            'id_turno'      => $turnoActivo?->id,
            'id_cliente'    => $datos['id_cliente'] ?? null,
            'id_usuario'    => $datos['id_usuario'],
            'estado'        => $datos['estado'] ?? 'confirmada',
            'fecha'         => substr($datos['fecha'], 0, 10),
            'hora'          => $datos['hora'] ?? null,
            'metodo_pago'   => $metodoPago,
            'estado_pago'   => $estadoPago,
            'monto_cobrado' => $montoCobrado,
            'monto_total'   => $montoTotal,
            'cuit'          => $cliente?->cuit,
            'motivo_descuento' => $datos['motivo_descuento'] ?? null,
        ]);

        // numero_ticket es un código interno generado offline (ver Home.jsx::
        // generarTicketId) para poder armar la venta sin ir al servidor — no es
        // legible ni es el número real de la venta. La descripción del movimiento
        // siempre muestra el id real, que es lo que el usuario reconoce en Caja/
        // Clientes/Movimientos.
        $subTipoMovimiento = "Venta #{$venta->id}";

        foreach ($datos['lineas'] as $linea) {
            // Una sola LineaVenta por línea del carrito, sea combo o producto suelto
            // — el ticket muestra "Combo Familiar x1", no el desglose de sus partes.
            // Sin id_producto: línea de "monto libre" — nombre queda guardado en la
            // propia línea porque no hay Producto del que leerlo después.
            LineaVenta::create([
                'id_venta'        => $venta->id,
                'id_producto'     => $linea['id_producto'] ?? null,
                'nombre'          => $linea['nombre'] ?? null,
                'precio_venta'    => $linea['precio_venta'],
                'precio_original' => $linea['precio_venta'],
                'cantidad'        => $linea['cantidad'],
            ]);
        }

        // El stock se descuenta de los productos base (demandaBase ya tiene los
        // combos expandidos a sus componentes), no de las líneas — un combo no
        // tiene stock propio para decrementar. Pasa por StockService::restar()
        // (no una resta manual sobre producto_stock) para que consuma los lotes
        // FEFO igual que cualquier otra baja — antes se restaba directo sobre
        // producto_stock.stock sin tocar `lotes`, así que las ventas (la baja de
        // stock más frecuente del sistema) nunca aplicaban FEFO y con el tiempo
        // desincronizaban las dos fuentes.
        foreach ($demandaBase as $idProducto => $cantidadRequerida) {
            $productoBase = $productosBase[$idProducto];
            $this->stockService->restar($idProducto, $idSucursal, $cantidadRequerida, $empresaId, $filasBloqueadas[$idProducto], $disponiblePorProducto[$idProducto]);

            // Auditoría de inventario — dentro de la misma transacción que la venta,
            // para que quede atómico (antes se creaba desde el front como un paso
            // aparte después de la venta, sin garantías si esa segunda llamada fallaba).
            MovimientoStock::create([
                'empresa_id'  => $empresaId,
                'id_sucursal' => $idSucursal,
                'id_producto' => $productoBase->id,
                'id_usuario'  => $datos['id_usuario'],
                'producto'    => $productoBase->producto,
                'codigo'      => $productoBase->codigo,
                'tipo'        => 'venta',
                'sub_tipo'    => $subTipoMovimiento,
                'cantidad'    => -$cantidadRequerida,
                'fecha'       => substr($datos['fecha'], 0, 10),
                'hora'        => $datos['hora'] ?? null,
            ]);
        }

        // Con pago dividido (ver variosPagos en Home.jsx), metodo_pago de la
        // venta solo guarda el PRIMER método cargado — usar eso acá adentro
        // metería TODO el monto o NADA en el arqueo según qué método haya
        // quedado primero, aunque una parte real sea efectivo físico en el
        // cajón. 'pagos' (si vino) trae el desglose real; solo la porción en
        // efectivo entra al arqueo, sea pago único o parte de uno dividido —
        // tarjeta/transferencia/qr no tocan plata física (mismo criterio que
        // agregarMovimiento en CajaController).
        $montoEfectivo = $pagos !== null
            ? collect($pagos)->where('metodo', 'efectivo')->sum('monto')
            : ($metodoPago === 'efectivo' ? $montoTotal : 0);

        if (!$esFiado && $montoEfectivo > 0 && $turnoActivo) {
            $turnoActivo->efectivo_actual += $montoEfectivo;
            $turnoActivo->ventas_efectivo += $montoEfectivo;
            $turnoActivo->save();
        }

        if ($cliente) {
            if ($puntosCanjeados > 0) {
                $cliente->decrement('puntos', $puntosCanjeados);
                MovimientoPuntos::create([
                    'empresa_id'      => $empresaId,
                    'id_cliente'      => $cliente->id,
                    'id_venta'        => $venta->id,
                    'tipo'            => 'canjeado',
                    'puntos'          => -$puntosCanjeados,
                    'saldo_posterior' => $cliente->puntos,
                    'nota'            => "Canje en venta #{$venta->id}",
                ]);
            }

            $empresa ??= Empresa::find($empresaId);
            if ($empresa?->puntos_activo && (float) $empresa->puntos_por_moneda > 0) {
                $puntosGanados = (int) floor($montoTotal / $empresa->puntos_por_moneda);
                if ($puntosGanados > 0) {
                    $cliente->increment('puntos', $puntosGanados);
                    MovimientoPuntos::create([
                        'empresa_id'      => $empresaId,
                        'id_cliente'      => $cliente->id,
                        'id_venta'        => $venta->id,
                        'tipo'            => 'ganado',
                        'puntos'          => $puntosGanados,
                        'saldo_posterior' => $cliente->puntos,
                        'nota'            => "Compra #{$venta->id}",
                    ]);
                }
            }
        }

        return $venta;
    }
}
