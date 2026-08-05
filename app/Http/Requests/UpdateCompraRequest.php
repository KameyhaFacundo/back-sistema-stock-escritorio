<?php

namespace App\Http\Requests;

use App\Rules\CantidadValidaParaProducto;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proveedor' => 'sometimes|exists:proveedores,id',
            'fecha' => 'sometimes|date',
            'estado' => 'sometimes|in:pendiente,confirmada,cancelada',
            'lineas' => 'sometimes|array|min:1',
            'lineas.*.id_producto' => 'required|exists:productos,id',
            'lineas.*.precio_compra' => 'required|numeric|min:0',
            'lineas.*.cantidad' => ['required', 'numeric', 'min:0.01', new CantidadValidaParaProducto()],
        ];
    }

    public function messages(): array
    {
        return [
            'id_proveedor.exists' => 'El proveedor seleccionado no existe',
            'fecha.date' => 'La fecha debe ser válida',
            'lineas.min' => 'Debe agregar al menos una línea de compra',
            'lineas.*.id_producto.exists' => 'El producto seleccionado no existe',
            'lineas.*.precio_compra.min' => 'El precio de compra no puede ser negativo',
            'lineas.*.cantidad.min' => 'La cantidad debe ser al menos 1',
        ];
    }
}
