<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Producto extends Model
{
    use HasFactory, SoftDeletes, HasTenant;

    protected $table = 'productos';

    protected $appends = ['imagen_url'];

    protected $fillable = [
        'empresa_id',
        'producto',
        'codigo',
        'imagen_path',
        'precio',
        'costo',
        'estado',
        'es_combo',
        'id_categoria',
        'id_proveedor',
        'fecha_vencimiento',
        'unidad_medida',
        'id_producto_padre',
        'id_grupo_talle',
        'id_talle',
        'tiene_variantes',
    ];

    protected $casts = [
        'precio'          => 'decimal:2',
        'costo'           => 'decimal:2',
        'estado'          => 'boolean',
        'es_combo'        => 'boolean',
        'tiene_variantes' => 'boolean',
        'fecha_vencimiento' => 'date:Y-m-d',
    ];

    public function getImagenUrlAttribute(): ?string
    {
        return $this->imagen_path ? Storage::disk('public')->url($this->imagen_path) : null;
    }

    // Relaciones
    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'id_categoria', 'id');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'id_proveedor', 'id');
    }

    public function lineasCompras()
    {
        return $this->hasMany(LineaCompra::class, 'id_producto', 'id');
    }

    public function lineasVentas()
    {
        return $this->hasMany(LineaVenta::class, 'id_producto', 'id');
    }

    // Los productos que componen este combo (vacío si no es un combo).
    public function componentes()
    {
        return $this->hasMany(ComboComponente::class, 'id_combo', 'id');
    }

    // Producto madre de esta variante (null si este producto no es una variante).
    public function padre()
    {
        return $this->belongsTo(Producto::class, 'id_producto_padre', 'id');
    }

    // Variantes (una por talle) de este producto madre — vacío si `tiene_variantes`
    // es false. Ver ProductosController::sincronizarVariantes().
    public function variantes()
    {
        return $this->hasMany(Producto::class, 'id_producto_padre', 'id');
    }

    // Solo lo usa el producto madre: qué curva de talles eligió.
    public function grupoTalle()
    {
        return $this->belongsTo(GrupoTalle::class, 'id_grupo_talle', 'id');
    }

    // Solo lo usa una variante: cuál talle puntual es esta fila.
    public function talle()
    {
        return $this->belongsTo(Talle::class, 'id_talle', 'id');
    }

    // Stock por sucursal (una fila de producto_stock por cada sucursal donde este
    // producto tiene stock registrado). El stock ya NO es una columna de productos
    // — ver producto_stock — justamente para no repetir el bug de accessor mágico
    // que pisaba en silencio un alias SQL (getSaldoPendienteAttribute) encontrado
    // antes en esta misma sesión: acá el llamador siempre pide el stock de una
    // sucursal puntual, nunca hay un valor "mágico" ambiguo.
    public function stocks()
    {
        return $this->hasMany(ProductoStock::class, 'id_producto', 'id');
    }

    public function stockEnSucursal(int $idSucursal): float
    {
        if ($this->relationLoaded('stocks')) {
            return (float) ($this->stocks->firstWhere('id_sucursal', $idSucursal)->stock ?? 0);
        }
        return (float) (ProductoStock::where('id_producto', $this->id)->where('id_sucursal', $idSucursal)->value('stock') ?? 0);
    }

    public function stockMinimoEnSucursal(int $idSucursal): float
    {
        if ($this->relationLoaded('stocks')) {
            return (float) ($this->stocks->firstWhere('id_sucursal', $idSucursal)->stock_minimo ?? 5);
        }
        return (float) (ProductoStock::where('id_producto', $this->id)->where('id_sucursal', $idSucursal)->value('stock_minimo') ?? 5);
    }

    /**
     * Stock real disponible en una sucursal: el propio para un producto normal, o
     * el mínimo de "stock del componente / cantidad que necesita" entre todos sus
     * componentes para un combo (evaluados en esa misma sucursal) — un combo no
     * tiene stock propio porque quedaría desincronizado apenas se vendiera
     * cualquiera de sus partes por separado.
     */
    public function stockDisponibleEnSucursal(int $idSucursal): float
    {
        if (!$this->es_combo) {
            return $this->stockEnSucursal($idSucursal);
        }

        if (!$this->relationLoaded('componentes') || $this->componentes->isEmpty()) {
            return 0;
        }

        // floor(), no intdiv(): un combo siempre se vende entero (1, 2, 3...), pero
        // el stock del componente o la cantidad de receta ya pueden ser fraccionarios
        // (ej: "0.3 kg de clavos por combo") — intdiv() exige enteros y el guard
        // max(1, ...) trataba cualquier receta menor a 1 como si fuera 1, ignorando
        // el valor real.
        $disponibles = $this->componentes->map(function ($comp) use ($idSucursal) {
            $productoComponente = $comp->relationLoaded('producto') ? $comp->producto : null;
            if (!$productoComponente) {
                return 0;
            }
            return floor($productoComponente->stockEnSucursal($idSucursal) / max(0.01, $comp->cantidad));
        });

        return (float) $disponibles->min();
    }
}
