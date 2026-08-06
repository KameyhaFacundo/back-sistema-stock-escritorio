<?php

namespace App\Http\Requests;

use App\Rules\CantidadValidaParaProducto;
use Illuminate\Foundation\Http\FormRequest;

class CreateVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'numero_ticket'        => 'nullable|string|max:50',
            'id_cliente'           => 'nullable|exists:clientes,id',
            'id_presupuesto'       => 'nullable|exists:presupuestos,id',
            'fecha'                => 'required|date',
            'hora'                 => 'nullable|string|max:20',
            'metodo_pago'          => 'nullable|string|max:50',
            'estado'               => 'nullable|in:pendiente,confirmada,cancelada',
            'lineas'               => 'required|array|min:1',
            // id_producto es opcional: una línea de "monto libre" (cargo sin
            // producto real, ej. "Servicio de instalación") no tiene uno, pero
            // en ese caso necesita su propio nombre para quedar identificada.
            'lineas.*.id_producto' => 'nullable|exists:productos,id',
            'lineas.*.nombre'      => 'required_without:lineas.*.id_producto|nullable|string|max:255',
            'lineas.*.precio_venta'=> 'required|numeric|min:0',
            'lineas.*.cantidad'    => ['required', 'numeric', 'min:0.01', new CantidadValidaParaProducto()],
            'ajuste'               => 'nullable|array',
            'ajuste.tipo'          => 'nullable|in:descuento,recargo',
            'ajuste.calculo'       => 'nullable|in:porcentaje,monto',
            'ajuste.valor'         => 'nullable|numeric|min:0',
            'puntos_canjeados'     => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'id_cliente.required' => 'El cliente es requerido',
            'id_cliente.exists' => 'El cliente seleccionado no existe',
            'fecha.required' => 'La fecha es requerida',
            'fecha.date' => 'La fecha debe ser válida',
            'lineas.required' => 'Debe agregar al menos una línea de venta',
            'lineas.min' => 'Debe agregar al menos una línea de venta',
            'lineas.*.id_producto.required' => 'El producto es requerido en cada línea',
            'lineas.*.id_producto.exists' => 'El producto seleccionado no existe',
            'lineas.*.precio_venta.required' => 'El precio de venta es requerido',
            'lineas.*.precio_venta.min' => 'El precio de venta no puede ser negativo',
            'lineas.*.cantidad.required' => 'La cantidad es requerida',
            'lineas.*.cantidad.min' => 'La cantidad debe ser al menos 1',
        ];
    }
}
