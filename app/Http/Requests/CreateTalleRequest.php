<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTalleRequest extends FormRequest
{
    // Mismo motivo que CreateGrupoTalleRequest — talles sueltos sin dueño
    // indumentaria no tienen ningún uso posible.
    public function authorize(): bool
    {
        return auth()->user()?->empresa?->tipo === 'indument';
    }

    public function rules(): array
    {
        return [
            'id_grupo_talle' => 'required|exists:grupos_talles,id',
            // Único dentro del grupo — sin esto, dos clicks en "Generar rango"
            // (o agregar el mismo valor a mano dos veces) dejaban el talle
            // duplicado en el selector del producto, con dos ids distintos.
            'valor'          => ['required', 'string', 'max:20', Rule::unique('talles', 'valor')->where(
                fn ($query) => $query->where('id_grupo_talle', $this->id_grupo_talle)
            )],
            'orden'          => 'nullable|integer',
            // Cuántas unidades de este talle vienen en una curva/bulto al
            // comprarle al proveedor — opcional, ver Compras > Comprar por curva.
            'cantidad_curva' => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'id_grupo_talle.required' => 'El grupo de talles es requerido',
            'id_grupo_talle.exists' => 'El grupo de talles seleccionado no existe',
            'valor.required' => 'El valor del talle es requerido',
            'valor.unique' => 'Ese talle ya existe en este grupo',
        ];
    }
}
