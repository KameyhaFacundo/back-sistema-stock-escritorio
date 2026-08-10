<?php

namespace App\Http\Requests;

use App\Rules\CantidadUnidadMedida;
use App\Rules\CantidadValidaParaProducto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'producto'     => 'required|string|max:200',
            'codigo'       => 'nullable|string|max:100|unique:productos,codigo',
            'codigo_barras' => 'nullable|string|max:100|unique:productos,codigo_barras',
            'precio'       => 'required|numeric|min:0',
            'costo'        => 'nullable|numeric|min:0',
            // Unidades fraccionables (kg/metro/litro) están disponibles para cualquier
            // rubro salvo indumentaria (una prenda no se vende "por kg") — nunca para
            // un combo tampoco (un combo siempre se vende 1/2/3 unidades enteras). Ver
            // el closure abajo, es el único lugar del sistema que decide esto.
            'unidad_medida' => ['nullable', 'string', Rule::in(['unidad', 'kg', 'metro', 'litro']), function ($attribute, $value, $fail) {
                if (!$value || $value === 'unidad') {
                    return;
                }
                if ($this->boolean('es_combo')) {
                    $fail('Un combo siempre se vende por unidad.');
                    return;
                }
                if (auth()->user()?->empresa?->tipo === 'indument') {
                    $fail('Esta unidad de medida no está disponible para tu tipo de comercio.');
                }
            }],
            'stock'        => ['nullable', 'numeric', 'min:0', new CantidadUnidadMedida('unidad_medida')],
            'stock_minimo' => ['nullable', 'numeric', 'min:0', new CantidadUnidadMedida('unidad_medida')],
            'estado'             => 'nullable|boolean',
            // Un combo puede mezclar productos de categorías distintas — no se le exige una.
            'id_categoria'       => 'required_unless:es_combo,true|nullable|exists:categorias,id',
            'id_proveedor'       => 'nullable|exists:proveedores,id',
            // Proveedores adicionales, además del principal (id_proveedor) — cada
            // uno puede tener su propio costo/código para este producto.
            'proveedores_alternativos'                    => 'nullable|array',
            'proveedores_alternativos.*.id_proveedor'     => 'required_with:proveedores_alternativos|exists:proveedores,id',
            'proveedores_alternativos.*.costo'            => 'nullable|numeric|min:0',
            'proveedores_alternativos.*.codigo_proveedor' => 'nullable|string|max:100',
            'fecha_vencimiento'  => 'nullable|date',
            'es_combo'                   => 'nullable|boolean',
            'componentes'                => 'required_if:es_combo,true|array|min:1',
            'componentes.*.id_producto'  => 'required_with:componentes|exists:productos,id',
            'componentes.*.cantidad'     => ['required_with:componentes', 'numeric', 'min:0.01', new CantidadValidaParaProducto()],
            // Variantes por talle son solo para indumentaria, y nunca para un combo
            // (un combo ya tiene su propia noción de "partes", no se mezclan) — mismo
            // único punto de entrada que unidad_medida para ferretería.
            'tiene_variantes' => ['nullable', 'boolean', function ($attribute, $value, $fail) {
                if (!$value) {
                    return;
                }
                if ($this->boolean('es_combo')) {
                    $fail('Un combo no puede tener variantes por talle.');
                    return;
                }
                if (auth()->user()?->empresa?->tipo !== 'indument') {
                    $fail('Las variantes por talle no están disponibles para tu tipo de comercio.');
                }
            }],
            'id_grupo_talle' => ['nullable', 'required_if:tiene_variantes,true', 'exists:grupos_talles,id'],
            // Un producto SIN variantes también puede llevar un talle propio y
            // fijo (ej. una prenda que solo viene en una talla) — a diferencia
            // de id_grupo_talle, este no genera nada, es un dato directo del
            // producto. Mutuamente excluyente con tiene_variantes: el talle de
            // un producto con variantes vive en cada variante generada, no acá.
            'id_talle' => ['nullable', 'exists:talles,id', function ($attribute, $value, $fail) {
                if (!$value) {
                    return;
                }
                if ($this->boolean('tiene_variantes')) {
                    $fail('Un producto con variantes no lleva un talle propio: el talle se asigna a cada variante generada.');
                    return;
                }
                if ($this->boolean('es_combo')) {
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
            'producto.required' => 'El nombre del producto es requerido',
            'producto.max' => 'El nombre del producto no puede superar los 200 caracteres',
            'precio.required' => 'El precio es requerido',
            'precio.numeric' => 'El precio debe ser un número',
            'precio.min' => 'El precio no puede ser negativo',
            'id_categoria.required_unless' => 'La categoría es requerida',
            'id_categoria.exists' => 'La categoría seleccionada no existe',
        ];
    }
}
