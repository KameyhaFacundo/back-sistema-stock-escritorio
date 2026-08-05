<?php

namespace App\Services;

use App\Models\Turno;

/**
 * Resuelve "el turno abierto de este usuario en esta sucursal" — antes esta
 * misma consulta estaba duplicada sin ningún filtro de sucursal en varios
 * lugares (CajaController, VentaCreacionService, ComprasController, VentasController).
 */
class TurnoService
{
    /**
     * $lock=true bloquea la fila (SELECT ... FOR UPDATE) para quien vaya a leer y
     * volver a escribir efectivo_actual/ventas_efectivo dentro de la misma
     * transacción (abrir movimiento de caja, cerrar turno, cobrar/pagar una deuda
     * en efectivo) — sin esto, dos operaciones concurrentes sobre el mismo turno
     * podían pisarse el incremento una a la otra (lost update sobre el saldo de
     * caja). Los llamados de solo lectura/validación (ver si hay caja abierta,
     * por ejemplo) no necesitan el lock.
     */
    public function activo(int $idUsuario, ?int $idSucursal, bool $lock = false): ?Turno
    {
        return Turno::where('id_usuario', $idUsuario)
            ->where('estado', 'abierta')
            ->when($idSucursal, fn($q) => $q->where('id_sucursal', $idSucursal))
            ->when($lock, fn($q) => $q->lockForUpdate())
            ->latest()
            ->first();
    }
}
