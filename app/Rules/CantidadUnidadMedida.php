<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Para el propio stock/stock_minimo de UN producto (a diferencia de
 * CantidadValidaParaProducto, que valida una línea de venta/compra que
 * referencia a OTRO producto por id) — exige entero si la unidad_medida
 * que viene en el mismo request es 'unidad' (o no vino), permite hasta
 * 2 decimales si no.
 */
class CantidadUnidadMedida implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    public function __construct(private string $campoUnidad = 'unidad_medida')
    {
    }

    public function setData(array $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || !is_numeric($value)) {
            return;
        }

        $unidad = data_get($this->data, $this->campoUnidad);

        if (($unidad ?: 'unidad') === 'unidad') {
            if ((float) $value != (int) $value) {
                $fail('Debe ser un número entero para este producto.');
            }
            return;
        }

        if (round((float) $value, 2) != (float) $value) {
            $fail('Admite hasta 2 decimales.');
        }
    }
}
