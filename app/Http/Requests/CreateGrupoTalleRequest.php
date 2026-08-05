<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateGrupoTalleRequest extends FormRequest
{
    // Grupos de talles solo tienen sentido (y solo son alcanzables desde la UI)
    // para indumentaria — sin esto, cualquier otro rubro podía crear filas
    // inertes vía API directa (nunca serían asignables a un producto igual,
    // por el mismo gate en CreateProductoRequest, pero quedarían huérfanas).
    public function authorize(): bool
    {
        return auth()->user()?->empresa?->tipo === 'indument';
    }

    public function rules(): array
    {
        return [
            'nombre' => 'required|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del grupo es requerido',
            'nombre.max' => 'El nombre del grupo no puede superar los 100 caracteres',
        ];
    }
}
