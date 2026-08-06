<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePresupuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_cliente' => 'nullable|exists:clientes,id',
            'fecha'      => 'required|date',
            'notas'      => 'nullable|string|max:2000',
            'lineas' => 'required|array|min:1',
            'lineas.*.id_producto'  => 'required|exists:productos,id',
            'lineas.*.precio_venta' => 'required|numeric|min:0',
            'lineas.*.cantidad'     => 'required|numeric|min:0.01',
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.required' => 'La fecha es requerida',
            'lineas.required' => 'Debe agregar al menos un producto',
            'lineas.min' => 'Debe agregar al menos un producto',
            'lineas.*.id_producto.required' => 'El producto es requerido en cada línea',
            'lineas.*.id_producto.exists' => 'El producto seleccionado no existe',
            'lineas.*.precio_venta.required' => 'El precio es requerido',
            'lineas.*.precio_venta.min' => 'El precio no puede ser negativo',
            'lineas.*.cantidad.required' => 'La cantidad es requerida',
            'lineas.*.cantidad.min' => 'La cantidad debe ser mayor a 0',
        ];
    }
}
