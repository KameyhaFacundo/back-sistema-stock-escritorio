<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class MovimientoCaja extends Model
{
    use HasTenant;

    protected $table = 'movimientos_caja';

    protected $fillable = ['empresa_id', 'id_turno', 'tipo', 'monto', 'motivo', 'hora'];

    protected $casts = ['monto' => 'decimal:2'];

    public function turno()
    {
        return $this->belongsTo(Turno::class, 'id_turno');
    }
}
