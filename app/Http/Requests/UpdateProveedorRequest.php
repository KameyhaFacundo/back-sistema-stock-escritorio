<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cuit' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('proveedores', 'cuit')->ignore($this->route('id')),
            ],
            'codigo' => 'nullable|string|max:100',
            'persona' => 'sometimes|string|max:255',
            'direccion' => 'nullable|string|max:500',
            'telefono' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'estado' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'cuit.unique' => 'El CUIT ya está registrado',
            'email.email' => 'El email debe ser válido',
        ];
    }
}
