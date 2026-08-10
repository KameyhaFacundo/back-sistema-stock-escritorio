<?php

namespace App\Http\Requests;

use App\Models\Producto;
use App\Rules\CantidadValidaParaProducto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('producto') ?? $this->route('id');
        $productoActual = Producto::find($id);
        // Un combo nunca puede terminar con unidad_medida fraccionable — si es_combo
        // no viene en este PUT (edición parcial), hay que mirar el valor ya guardado.
        $esCombo = $this->has('es_combo') ? $this->boolean('es_combo') : (bool) $productoActual?->es_combo;
        // Idem para stock_minimo: si unidad_medida no viene en este PUT, hay que
        // validar contra la unidad que el producto ya tiene guardada, no asumir
        // 'unidad' a ciegas (le exigiría entero a un producto que ya es en kg).
        $unidadEfectiva = $this->has('unidad_medida') ? $this->input('unidad_medida') : $productoActual?->unidad_medida;
        // Idem para id_talle: si tiene_variantes no viene en este PUT, hay que
        // mirar el valor ya guardado para saber si un talle directo es válido.
        $tieneVariantesEfectivo = $this->has('tiene_variantes') ? $this->boolean('tiene_variantes') : (bool) $productoActual?->tiene_variantes;

        return [
            'producto'     => 'sometimes|string|max:200',
            'codigo'       => "sometimes|nullable|string|max:100|unique:productos,codigo,{$id}",
            'codigo_barras' => "sometimes|nullable|string|max:100|unique:productos,codigo_barras,{$id}",
            'precio'       => 'sometimes|numeric|min:0',
            'costo'        => 'sometimes|nullable|numeric|min:0',
            'unidad_medida' => ['sometimes', 'nullable', 'string', Rule::in(['unidad', 'kg', 'metro', 'litro']), function ($attribute, $value, $fail) use ($esCombo) {
                if (!$value || $value === 'unidad') {
                    return;
                }
                if ($esCombo) {
                    $fail('Un combo siempre se vende por unidad.');
                    return;
                }
                if (auth()->user()?->empresa?->tipo !== 'ferret') {
                    $fail('Esta unidad de medida no está disponible para tu tipo de comercio.');
                }
            }],
            'stock_minimo' => ['sometimes', 'numeric', 'min:0', function ($attribute, $value, $fail) use ($unidadEfectiva) {
                if (($unidadEfectiva ?: 'unidad') === 'unidad' && (float) $value != (int) $value) {
                    $fail('El stock mínimo debe ser un número entero para este producto.');
                }
            }],
            'estado'            => 'sometimes|boolean',
            'id_categoria'      => 'sometimes|nullable|exists:categorias,id',
            'id_proveedor'      => 'sometimes|nullable|exists:proveedores,id',
            'proveedores_alternativos'                    => 'sometimes|nullable|array',
            'proveedores_alternativos.*.id_proveedor'     => 'required_with:proveedores_alternativos|exists:proveedores,id',
            'proveedores_alternativos.*.costo'            => 'nullable|numeric|min:0',
            'proveedores_alternativos.*.codigo_proveedor' => 'nullable|string|max:100',
            'fecha_vencimiento' => 'sometimes|nullable|date',
            'es_combo'                   => 'sometimes|boolean',
            'componentes'                => 'sometimes|array|min:1',
            'componentes.*.id_producto'  => 'required_with:componentes|exists:productos,id',
            'componentes.*.cantidad'     => ['required_with:componentes', 'numeric', 'min:0.01', new CantidadValidaParaProducto()],
            'tiene_variantes' => ['sometimes', 'boolean', function ($attribute, $value, $fail) use ($esCombo) {
                if (!$value) {
                    return;
                }
                if ($esCombo) {
                    $fail('Un combo no puede tener variantes por talle.');
                    return;
                }
                if (auth()->user()?->empresa?->tipo !== 'indument') {
                    $fail('Las variantes por talle no están disponibles para tu tipo de comercio.');
                }
            }],
            'id_grupo_talle' => ['sometimes', 'nullable', 'exists:grupos_talles,id'],
            'id_talle' => ['sometimes', 'nullable', 'exists:talles,id', function ($attribute, $value, $fail) use ($esCombo, $tieneVariantesEfectivo) {
                if (!$value) {
                    return;
                }
                if ($tieneVariantesEfectivo) {
                    $fail('Un producto con variantes no lleva un talle propio: el talle se asigna a cada variante generada.');
                    return;
                }
                if ($esCombo) {
                    $fail('Un combo no puede tener un talle.');
                    return;
                }
                if (auth()->user()?->empresa?->tipo !== 'indument') {
                    $fail('Los talles no están disponibles para tu tipo de comercio.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'producto.max' => 'El nombre del producto no puede superar los 200 caracteres',
            'precio.numeric' => 'El precio debe ser un número',
            'precio.min' => 'El precio no puede ser negativo',
            'id_categoria.exists' => 'La categoría seleccionada no existe',
        ];
    }
}
