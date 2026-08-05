<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Sin reglas para "lineas" a propósito: VentasController::update() ya no
    // acepta editar las líneas de una venta confirmada (ver el comentario ahí
    // — no ajustaba stock ni caja). Para eso existe /anular + una venta nueva.
    public function rules(): array
    {
        return [
            'id_cliente' => 'sometimes|exists:clientes,id',
            'fecha' => 'sometimes|date',
            'estado' => 'sometimes|in:pendiente,confirmada,cancelada',
        ];
    }

    public function messages(): array
    {
        return [
            'id_cliente.exists' => 'El cliente seleccionado no existe',
            'fecha.date' => 'La fecha debe ser válida',
        ];
    }
}
