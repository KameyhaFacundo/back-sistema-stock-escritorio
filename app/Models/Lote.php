<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class Lote extends Model
{
    use HasTenant;

    protected $table = 'lotes';

    protected $fillable = [
        'empresa_id',
        'id_producto',
        'id_sucursal',
        'cantidad',
        'fecha_vencimiento',
        'id_compra',
        'id_usuario',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'fecha_vencimiento' => 'date',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal');
    }

    public function compra()
    {
        return $this->belongsTo(Compra::class, 'id_compra');
    }

    /**
     * Lotes activos: aquellos con cantidad > 0, ordenados por fecha_vencimiento (FEFO)
     */
    public function scopeActivos($query)
    {
        return $query->where('cantidad', '>', 0)->orderBy('fecha_vencimiento', 'asc');
    }

    /**
     * Lotes proximos a vencer dentro de N dias
     */
    public function scopeProximosAVencer($query, int $dias = 7)
    {
        return $query->where('cantidad', '>', 0)
            ->whereNotNull('fecha_vencimiento')
            ->where('fecha_vencimiento', '>=', now()->toDateString())
            ->where('fecha_vencimiento', '<=', now()->addDays($dias)->toDateString())
            ->orderBy('fecha_vencimiento', 'asc');
    }
}
