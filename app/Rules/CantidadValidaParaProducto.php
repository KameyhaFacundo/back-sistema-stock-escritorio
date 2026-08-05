<?php

namespace App\Rules;

use App\Models\Producto;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Cantidad válida para una línea de venta/compra/movimiento: entera si el
 * producto referenciado usa la unidad 'unidad' (el default de todo el mundo
 * salvo ferretería), o hasta 2 decimales si el producto tiene una unidad
 * fraccionable (kg/metro/litro).
 *
 * Se fija en el producto (`unidad_medida`), no en el tipo de empresa — el
 * único lugar que decide si un producto PUEDE tener una unidad fraccionable
 * es CreateProductoRequest/UpdateProductoRequest (ahí sí se chequea
 * empresa.tipo === 'ferret'). Una vez que un producto ya tiene esa unidad
 * asignada, todo lo demás (esta regla incluida) confía en ese dato — así
 * el motor queda reutilizable para cuando otro rubro la necesite.
 *
 * Espera que el campo hermano "id_producto" viva al lado de "cantidad" en
 * el mismo nivel del payload (ej: "lineas.*.cantidad" junto a
 * "lineas.*.id_producto", o "cantidad"/"id_producto" sueltos). Si no hay
 * id_producto (ej: una línea de "monto libre" sin producto real), se
 * exige entero — mismo comportamiento que había antes de esta regla.
 */
class CantidadValidaParaProducto implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_numeric($value)) {
            $fail('La cantidad debe ser un número.');
            return;
        }

        $idProducto = data_get($this->data, preg_replace('/cantidad$/', 'id_producto', $attribute));
        $unidad     = $idProducto ? Producto::where('id', $idProducto)->value('unidad_medida') : null;

        if (($unidad ?? 'unidad') === 'unidad') {
            if ((float) $value != (int) $value) {
                $fail('La cantidad debe ser un número entero para este producto.');
            }
            return;
        }

        // Unidad fraccionable: hasta 2 decimales, siempre positiva.
        if (round((float) $value, 2) != (float) $value) {
            $fail('La cantidad admite hasta 2 decimales.');
        }
    }
}
