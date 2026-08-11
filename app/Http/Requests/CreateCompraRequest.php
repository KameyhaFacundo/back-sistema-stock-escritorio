<?php

namespace App\Http\Requests;

use App\Rules\CantidadValidaParaProducto;
use Illuminate\Foundation\Http\FormRequest;

class CreateCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proveedor' => 'required|exists:proveedores,id',
            'fecha'        => 'required|date',
            'metodo_pago'  => 'nullable|string|max:50',
            // Desglose real de "varios métodos" (mismo patrón que 'pagos' en
            // CreateVentaRequest) — si la suma no cubre el total, el resto queda
            // de saldo en cuenta corriente con el proveedor (pago parcial). Sin
            // esto, se preserva el comportamiento de siempre: pagado 100% salvo
            // que metodo_pago sea "cuenta_corriente" (0% pagado).
            'pagos'                => 'nullable|array',
            'pagos.*.metodo'       => 'required_with:pagos|string|max:50',
            'pagos.*.monto'        => 'required_with:pagos|numeric|min:0',
            'estado'       => 'nullable|in:pendiente,confirmada,cancelada',
            'lineas' => 'required|array|min:1',
            'lineas.*.id_producto' => 'required|exists:productos,id',
            'lineas.*.precio_compra' => 'required|numeric|min:0',
            'lineas.*.cantidad' => ['required', 'numeric', 'min:0.01', new CantidadValidaParaProducto()],
        ];
    }

    public function messages(): array
    {
        return [
            'id_proveedor.required' => 'El proveedor es requerido',
            'id_proveedor.exists' => 'El proveedor seleccionado no existe',
            'fecha.required' => 'La fecha es requerida',
            'fecha.date' => 'La fecha debe ser válida',
            'lineas.required' => 'Debe agregar al menos una línea de compra',
            'lineas.min' => 'Debe agregar al menos una línea de compra',
            'lineas.*.id_producto.required' => 'El producto es requerido en cada línea',
            'lineas.*.id_producto.exists' => 'El producto seleccionado no existe',
            'lineas.*.precio_compra.required' => 'El precio de compra es requerido',
            'lineas.*.precio_compra.min' => 'El precio de compra no puede ser negativo',
            'lineas.*.cantidad.required' => 'La cantidad es requerida',
            'lineas.*.cantidad.min' => 'La cantidad debe ser al menos 1',
        ];
    }
}
