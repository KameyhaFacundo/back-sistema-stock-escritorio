<?php

namespace App\Http\Requests;

use App\Rules\CantidadValidaParaProducto;
use Illuminate\Foundation\Http\FormRequest;

class CreateCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proveedor' => 'required|exists:proveedores,id',
            'fecha'        => 'required|date',
            'metodo_pago'  => 'nullable|string|max:50',
            'estado'       => 'nullable|in:pendiente,confirmada,cancelada',
            'lineas' => 'required|array|min:1',
            'lineas.*.id_producto' => 'required|exists:productos,id',
            'lineas.*.precio_compra' => 'required|numeric|min:0',
            'lineas.*.cantidad' => ['required', 'numeric', 'min:0.01', new CantidadValidaParaProducto()],
        ];
    }

    public function messages(): array
    {
        return [
            'id_proveedor.required' => 'El proveedor es requerido',
            'id_proveedor.exists' => 'El proveedor seleccionado no existe',
            'fecha.required' => 'La fecha es requerida',
            'fecha.date' => 'La fecha debe ser válida',
            'lineas.required' => 'Debe agregar al menos una línea de compra',
            'lineas.min' => 'Debe agregar al menos una línea de compra',
            'lineas.*.id_producto.required' => 'El producto es requerido en cada línea',
            'lineas.*.id_producto.exists' => 'El producto seleccionado no existe',
            'lineas.*.precio_compra.required' => 'El precio de compra es requerido',
            'lineas.*.precio_compra.min' => 'El precio de compra no puede ser negativo',
            'lineas.*.cantidad.required' => 'La cantidad es requerida',
            'lineas.*.cantidad.min' => 'La cantidad debe ser al menos 1',
        ];
    }
}
