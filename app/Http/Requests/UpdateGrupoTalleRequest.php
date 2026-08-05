<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGrupoTalleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->empresa?->tipo === 'indument';
    }

    public function rules(): array
    {
        return [
            'nombre' => 'sometimes|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.max' => 'El nombre del grupo no puede superar los 100 caracteres',
        ];
    }
}
