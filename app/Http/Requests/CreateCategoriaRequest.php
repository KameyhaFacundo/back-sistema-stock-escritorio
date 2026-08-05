<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categoria' => 'required|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'categoria.required' => 'El nombre de la categoría es requerido',
            'categoria.max' => 'El nombre de la categoría no puede superar los 100 caracteres',
        ];
    }
}
