<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateDevolucionVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Validación estructural nomás acá — que la línea pertenezca a esta
            // venta y que la cantidad no exceda lo disponible se valida en
            // DevolucionVentaService, que sí tiene el contexto de la venta.
            'lineas'                     => 'required|array|min:1',
            'lineas.*.id_linea_venta'    => 'required|exists:lineas_ventas,id_linea',
            'lineas.*.cantidad'          => 'required|numeric|min:0.01',
            'motivo'                     => 'nullable|string|max:255',
            // Cómo se le resuelve al cliente la diferencia a favor, si la hay
            // (ver ModalDevolucionVenta en Dashboard.jsx) — nullable porque no
            // toda devolución genera una diferencia a favor (puede que el
            // cliente todavía deba parte de la venta, ver saldoPendienteAntes
            // en DevolucionVentaService). Sin este dato, se asume 'efectivo'
            // (mismo comportamiento que había antes de este campo existir).
            'forma_reintegro'            => 'nullable|in:efectivo,transferencia,mercaderia,saldo_favor',
        ];
    }

    public function messages(): array
    {
        return [
            'lineas.required' => 'Elegí al menos un producto para devolver',
            'lineas.*.cantidad.min' => 'La cantidad a devolver debe ser mayor a 0',
        ];
    }
}
