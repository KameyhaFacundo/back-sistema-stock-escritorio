<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPlanLimits;
use App\Jobs\ScanInvoiceJob;
use Illuminate\Http\Request;

class EscanearController extends Controller
{
    use ChecksPlanLimits;

    public function factura(Request $request)
    {
        $request->validate([
            'imagen'    => 'required|string',
            'mime_type' => 'nullable|string',
        ]);

        $empresa = auth()->user()->empresa;
        if ($empresa && ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'ia',
            'Escanear facturas con IA está disponible en el plan IA. Actualizá tu plan para usarlo.'
        ))) {
            return $resp;
        }

        $apiKey = config('services.gemini.api_key');
        $mimeType = $request->mime_type ?? 'image/jpeg';

        if (!$apiKey) {
            return response()->json(['error' => 'Clave de Gemini no configurada.'], 500);
        }

        $prompt =
            'Sos un asistente que extrae datos de facturas de proveedores argentinas. ' .
            'Analizá esta imagen y devolvé SOLO un JSON con esta estructura exacta (sin markdown, sin texto extra): ' .
            '{"proveedor": "nombre completo del proveedor", "fecha": "YYYY-MM-DD", ' .
            '"numero_factura": "número o null", ' .
            '"productos": [{"nombre": "nombre del producto", "cantidad": número_entero, "precio_unitario": número}], ' .
            '"total": número}. ' .
            'Si no podés leer un campo, usá null. Los precios deben ser números sin símbolos ni puntos de miles.';

        // Despachar a queue si está configurada, si no ejecutar sync
        if (config('queue.default') !== 'sync') {
            ScanInvoiceJob::dispatch($request->imagen, $mimeType, $prompt);
            return response()->json([
                'data'    => null,
                'message' => 'Factura en proceso de análisis. Recibirás una notificación cuando esté lista.',
                'queued'  => true,
            ]);
        }

        // Fallback síncrono (desarrollo)
        $result = (new ScanInvoiceJob($request->imagen, $mimeType, $prompt))->handle();

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json(['data' => $result]);
    }
}
