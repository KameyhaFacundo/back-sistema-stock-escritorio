<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->cuit === '') {
            $this->merge(['cuit' => null]);
        }
    }

    public function rules(): array
    {
        $empresaId = auth()->user()?->empresa_id;

        return [
            'cuit' => 'nullable|string|max:20|unique:clientes,cuit',
            'persona' => 'required|string|max:255',
            'direccion' => 'nullable|string|max:500',
            'telefono' => 'nullable|string|max:50',
            // Único por empresa, no global — dos negocios distintos pueden
            // perfectamente tener cada uno un cliente con el mismo email.
            'email' => ['nullable', 'email', 'max:100', Rule::unique('clientes', 'email')->where(fn($q) => $q->where('empresa_id', $empresaId))],
            'condicion_iva' => 'nullable|in:Monotributista,Responsable Inscripto,Exento,Consumidor Final',
            'estado' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'cuit.unique' => 'El CUIT ya está registrado',
            'persona.required' => 'El nombre o razón social es requerido',
            'email.email' => 'El email debe ser válido',
            'email.unique' => 'Ya existe un cliente con ese email',
        ];
    }
}
