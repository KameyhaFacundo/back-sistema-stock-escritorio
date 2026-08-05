<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class ComboComponente extends Model
{
    use HasTenant;

    protected $table = 'combo_componentes';

    protected $fillable = [
        'empresa_id',
        'id_combo',
        'id_producto',
        'cantidad',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
    ];

    public function combo()
    {
        return $this->belongsTo(Producto::class, 'id_combo', 'id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto', 'id');
    }
}
