<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateDevolucionCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Validación estructural nomás acá — que la línea pertenezca a esta
            // compra, que no exceda lo disponible y que haya stock físico
            // suficiente para sacar se valida en DevolucionCompraService.
            'lineas'                  => 'required|array|min:1',
            'lineas.*.id_linea_compra' => 'required|exists:lineas_compras,id_linea',
            'lineas.*.cantidad'       => 'required|numeric|min:0.01',
            'motivo'                  => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'lineas.required' => 'Elegí al menos un producto para devolver',
            'lineas.*.cantidad.min' => 'La cantidad a devolver debe ser mayor a 0',
        ];
    }
}
