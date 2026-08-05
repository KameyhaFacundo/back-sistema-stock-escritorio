<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'des_usu' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
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
            'des_usu.required' => 'El nombre de usuario es requerido',
            'email.required' => 'El email es requerido',
            'email.email' => 'El email debe ser válido',
            'email.unique' => 'El email ya está en uso',
            'password.required' => 'La contraseña es requerida',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres',
        ];
    }
}
