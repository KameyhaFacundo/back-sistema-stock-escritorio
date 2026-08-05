<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateRolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo' => 'required|string|max:50|unique:roles,codigo',
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:255',
            'permisos' => 'nullable|array',
            'permisos.*' => 'exists:permisos,id',
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'El código del rol es requerido',
            'codigo.unique' => 'El código ya está en uso',
            'nombre.required' => 'El nombre del rol es requerido',
        ];
    }
}
