<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categoria' => 'sometimes|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'categoria.max' => 'El nombre de la categoría no puede superar los 100 caracteres',
        ];
    }
}
