<?php

namespace App\Services;

use App\Models\Lote;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\ProductoStock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * GET /productos cachea su respuesta 5 minutos (ver ProductosController::index)
     * bajo una key versionada por empresa. Bumpear esa versión es lo único que la
     * invalida — antes solo lo hacía ProductosController al crear/editar un producto,
     * así que una venta, compra, ajuste o transferencia (todo pasa por acá) dejaba
     * el stock bien en la base pero la lista de Productos seguía sirviendo el valor
     * viejo hasta que la caché expiraba sola.
     *
     * Pública para que LotesController pueda invalidarla cuando edita/borra un lote
     * existente tocando producto_stock.stock directamente (no puede pasar por
     * agregar()/restar() para eso — ver los comentarios ahí).
     */
    public function invalidarCacheProductos(int $empresaId): void
    {
        $versionKey = "productos:list:version:{$empresaId}";
        Cache::forever($versionKey, Cache::get($versionKey, 1) + 1);
    }

    public function disponible(int $idProducto, int $idSucursal): float
    {
        return (float) Lote::where('id_producto', $idProducto)
            ->where('id_sucursal', $idSucursal)
            ->where('cantidad', '>', 0)
            ->sum('cantidad');
    }

    public function lockOrCrear(int $idProducto, int $idSucursal, int $empresaId): ProductoStock
    {
        $fila = ProductoStock::where('id_producto', $idProducto)
            ->where('id_sucursal', $idSucursal)
            ->lockForUpdate()
            ->first();

        if (!$fila) {
            $fila = ProductoStock::create([
                'empresa_id'   => $empresaId,
                'id_producto'  => $idProducto,
                'id_sucursal'  => $idSucursal,
                'stock'        => 0,
                'stock_minimo' => 5,
            ]);
        }

        return $fila;
    }

    /**
     * Suma stock creando un lote. Si se indica $fechaVencimiento, el lote lo registra.
     */
    public function agregar(int $idProducto, int $idSucursal, float $cantidad, int $empresaId, ?string $fechaVencimiento = null, ?int $idCompra = null, ?int $idUsuario = null): ProductoStock
    {
        return DB::transaction(function () use ($idProducto, $idSucursal, $cantidad, $empresaId, $fechaVencimiento, $idCompra, $idUsuario) {
            $fila = $this->lockOrCrear($idProducto, $idSucursal, $empresaId);
            $fila->stock = max(0, $fila->stock + $cantidad);
            $fila->save();

            Lote::create([
                'empresa_id'         => $empresaId,
                'id_producto'        => $idProducto,
                'id_sucursal'        => $idSucursal,
                'cantidad'           => $cantidad,
                'fecha_vencimiento'  => $fechaVencimiento,
                'id_compra'          => $idCompra,
                'id_usuario'         => $idUsuario,
            ]);

            $this->invalidarCacheProductos($empresaId);

            return $fila;
        });
    }

    /**
     * Resta stock consumiendo lotes FEFO (los que vencen primero).
     *
     * $filaYaBloqueada/$disponibleYaValidado: para un caller que YA bloqueó esta
     * fila y calculó disponible() en la MISMA transacción, sin hacer ningún otro
     * trabajo en el medio que pudiera cambiar los lotes (ver VentaCreacionService,
     * que valida el stock de todos los productos de la venta antes de descontar
     * ninguno) — evita repetir el SELECT...FOR UPDATE y el SUM(lotes.cantidad) que
     * de otra forma se hacen dos veces por producto en cada venta, el camino más
     * caliente de todo el sistema. Sin pasarlos, se comporta igual que antes.
     */
    public function restar(int $idProducto, int $idSucursal, float $cantidad, int $empresaId, ?ProductoStock $filaYaBloqueada = null, ?float $disponibleYaValidado = null): ProductoStock
    {
        return DB::transaction(function () use ($idProducto, $idSucursal, $cantidad, $empresaId, $filaYaBloqueada, $disponibleYaValidado) {
            $fila = $filaYaBloqueada ?? $this->lockOrCrear($idProducto, $idSucursal, $empresaId);
            $disponible = $disponibleYaValidado ?? $this->disponible($idProducto, $idSucursal);

            if ($disponible < $cantidad) {
                throw new \RuntimeException("Stock insuficiente. Disponible: {$disponible}, solicitado: {$cantidad}");
            }

            $restante = $cantidad;
            $lotes = Lote::where('id_producto', $idProducto)
                ->where('id_sucursal', $idSucursal)
                ->where('cantidad', '>', 0)
                ->orderByRaw('COALESCE(fecha_vencimiento, \'9999-12-31\') ASC')
                ->lockForUpdate()
                ->get();

            foreach ($lotes as $lote) {
                if ($restante <= 0) break;
                $tomar = min($lote->cantidad, $restante);
                $lote->cantidad -= $tomar;
                $lote->save();
                $restante -= $tomar;
            }

            $fila->stock = max(0, $fila->stock - $cantidad);
            $fila->save();

            $this->invalidarCacheProductos($empresaId);

            return $fila;
        });
    }

    public function ajustar(int $idProducto, int $idSucursal, float $delta, int $empresaId): ProductoStock
    {
        if ($delta > 0) {
            return $this->agregar($idProducto, $idSucursal, $delta, $empresaId);
        }
        return $this->restar($idProducto, $idSucursal, abs($delta), $empresaId);
    }

    public function transferir(Producto $producto, int $idSucursalOrigen, int $idSucursalDestino, float $cantidad, int $idUsuario): array
    {
        if ($idSucursalOrigen === $idSucursalDestino) {
            throw new \RuntimeException('La sucursal de origen y destino no pueden ser la misma');
        }
        if ($cantidad <= 0) {
            throw new \RuntimeException('La cantidad a transferir debe ser mayor a cero');
        }

        return DB::transaction(function () use ($producto, $idSucursalOrigen, $idSucursalDestino, $cantidad, $idUsuario) {
            $this->restar($producto->id, $idSucursalOrigen, $cantidad, $producto->empresa_id);
            $this->agregar($producto->id, $idSucursalDestino, $cantidad, $producto->empresa_id, null, null, $idUsuario);

            $fecha = now()->format('Y-m-d');
            $hora  = now()->format('H:i');

            $salida = MovimientoStock::create([
                'empresa_id'  => $producto->empresa_id,
                'id_sucursal' => $idSucursalOrigen,
                'id_producto' => $producto->id,
                'id_usuario'  => $idUsuario,
                'producto'    => $producto->producto,
                'codigo'      => $producto->codigo,
                'tipo'        => 'transferencia_salida',
                'sub_tipo'    => "Transferencia a sucursal #{$idSucursalDestino}",
                'cantidad'    => -$cantidad,
                'fecha'       => $fecha,
                'hora'        => $hora,
            ]);

            $entrada = MovimientoStock::create([
                'empresa_id'  => $producto->empresa_id,
                'id_sucursal' => $idSucursalDestino,
                'id_producto' => $producto->id,
                'id_usuario'  => $idUsuario,
                'producto'    => $producto->producto,
                'codigo'      => $producto->codigo,
                'tipo'        => 'transferencia_entrada',
                'sub_tipo'    => "Transferencia desde sucursal #{$idSucursalOrigen}",
                'cantidad'    => $cantidad,
                'fecha'       => $fecha,
                'hora'        => $hora,
            ]);

            return ['salida' => $salida, 'entrada' => $entrada];
        });
    }
}
