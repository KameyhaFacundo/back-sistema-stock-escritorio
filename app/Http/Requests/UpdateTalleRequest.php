<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTalleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->empresa?->tipo === 'indument';
    }

    public function rules(): array
    {
        return [
            'valor'          => 'sometimes|string|max:20',
            'orden'          => 'sometimes|integer',
            'cantidad_curva' => 'sometimes|nullable|integer|min:0',
        ];
    }
}
