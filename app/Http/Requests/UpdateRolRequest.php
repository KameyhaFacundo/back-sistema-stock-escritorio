<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rolId = $this->route('id');

        return [
            'codigo' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('roles', 'codigo')->ignore($rolId),
            ],
            'nombre' => 'sometimes|string|max:255',
            'descripcion' => 'nullable|string|max:255',
            'permisos' => 'nullable|array',
            'permisos.*' => 'exists:permisos,id',
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.unique' => 'El código ya está en uso',
        ];
    }
}
