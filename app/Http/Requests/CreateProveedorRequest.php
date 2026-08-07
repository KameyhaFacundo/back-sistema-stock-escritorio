<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // El front manda '' cuando el usuario no cargó CUIT (es opcional) — sin
    // esto, "" quedaría guardado como string vacío y el unique de abajo
    // chocaría entre sí a partir del segundo proveedor sin CUIT.
    protected function prepareForValidation(): void
    {
        if ($this->cuit === '') {
            $this->merge(['cuit' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'cuit' => ['nullable', 'string', 'max:20', Rule::unique('proveedores', 'cuit')],
            'codigo' => 'nullable|string|max:100',
            'persona' => 'required|string|max:255',
            'direccion' => 'nullable|string|max:500',
            'telefono' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'estado' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'cuit.unique' => 'El CUIT ya está registrado',
            'persona.required' => 'El nombre o razón social es requerido',
            'email.email' => 'El email debe ser válido',
        ];
    }
}
