<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user') ? $this->route('user')->nro_usu : $this->route('id');

        return [
            'des_usu' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                Rule::unique('users', 'email')->ignore($userId, 'nro_usu'),
            ],
            'password' => 'nullable|string|min:6',
            'id_tipo_usuario' => 'nullable|exists:tipo_usuarios,id',
            'id_rol' => 'nullable|exists:roles,id',
            'id_sucursal' => 'nullable|exists:sucursales,id',
            'permisos' => 'nullable|array',
            'permisos.*' => 'exists:permisos,id',
        ];
    }

    public function messages(): array
    {
        return [
            'email.email' => 'El email debe ser válido',
            'email.unique' => 'El email ya está en uso',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres',
        ];
    }
}
