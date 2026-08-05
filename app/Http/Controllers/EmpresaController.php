<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPlanLimits;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\MovimientoPuntos;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Venta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class EmpresaController extends Controller
{
    use ChecksPlanLimits;

    /**
     * Actualiza los datos de perfil (no de facturación/plan) de la empresa del
     * usuario autenticado. Pensado para la pestaña "Negocio" de Configuración.
     */
    public function update(Request $request): JsonResponse
    {
        $empresa = auth('api')->user()->empresa;
        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada'], 400);
        }

        $data = $request->validate([
            'nombre'             => 'sometimes|required|string|max:255',
            'cuit'               => 'nullable|string|max:20',
            'tipo'               => 'nullable|string|max:100',
            'pais'               => 'nullable|string|max:100',
            'direccion'          => 'nullable|string|max:255',
            'whatsapp'           => 'nullable|string|max:30',
            'condicion_fiscal'   => 'nullable|string|max:100',
            'iibb'               => 'nullable|string|max:50',
            'inicio_actividad'   => 'nullable|date',
            'email'              => 'nullable|email|max:255',
            'puntos_activo'      => 'sometimes|boolean',
            'puntos_por_moneda'  => 'nullable|numeric|min:0.01',
            'puntos_valor_pesos' => 'nullable|numeric|min:0.01',
            // "¿Necesitás facturar?" del onboarding — activable/desactivable
            // después desde Configuración → Facturación (ver ConfigModal.jsx).
            'arca'               => 'sometimes|boolean',
            // Qué tarjetas de método de pago mostrar en el arqueo de caja — ver
            // CajaController::agregarTotalesPorMetodo(). null/omitido = las 5.
            'arqueo_metodos_visibles'   => 'sometimes|nullable|array',
            'arqueo_metodos_visibles.*' => 'string|in:efectivo,tarjeta,transferencia,qr,fiado',
        ]);

        $empresa->update($data);

        return response()->json(['success' => true, 'empresa' => $empresa->fresh()]);
    }

    /**
     * POST /empresa/logo — sube (o reemplaza) el logo de la empresa del usuario
     * autenticado. Antes esto se guardaba en localStorage['empresa_logo'], una
     * key global del navegador sin distinción de empresa — cambiar el logo de
     * un cliente lo cambiaba para todos los que se vieran desde ese navegador.
     * Ahora queda en disco (disk "public"), asociado al id de la empresa.
     */
    public function subirLogo(Request $request): JsonResponse
    {
        $empresa = auth('api')->user()->empresa;
        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada'], 400);
        }

        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        if ($empresa->logo_path) {
            Storage::disk('public')->delete($empresa->logo_path);
        }

        $path = $request->file('logo')->store("logos/{$empresa->id}", 'public');
        $empresa->update(['logo_path' => $path]);

        return response()->json(['success' => true, 'empresa' => $empresa->fresh()]);
    }

    /**
     * DELETE /empresa/logo — vuelve al logo por defecto de la app.
     */
    public function eliminarLogo(): JsonResponse
    {
        $empresa = auth('api')->user()->empresa;
        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada'], 400);
        }

        if ($empresa->logo_path) {
            Storage::disk('public')->delete($empresa->logo_path);
            $empresa->update(['logo_path' => null]);
        }

        return response()->json(['success' => true, 'empresa' => $empresa->fresh()]);
    }

    /**
     * POST /empresa/arca — carga el certificado y clave privada de ARCA
     * (WSAA/WSFE) de la empresa del usuario autenticado. A diferencia del
     * logo, esto NUNCA se guarda como archivo en disco: el contenido va
     * directo a columnas encriptadas (ver Empresa::$casts) — ni siquiera
     * este método llega a escribir un archivo temporal.
     */
    public function actualizarArca(Request $request): JsonResponse
    {
        $empresa = auth('api')->user()->empresa;
        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada'], 400);
        }
        if ($resp = $this->funcionNoDisponibleEnPlan(
            $empresa, 'facturacion',
            'La facturación electrónica (ARCA) está disponible desde el plan Pro. Actualizá tu plan para configurarla.'
        )) {
            return $resp;
        }

        $request->validate([
            'cert'         => 'required|file|max:512',
            'key'          => 'required|file|max:512',
            'passphrase'   => 'nullable|string|max:255',
            'punto_venta'  => 'required|integer|min:1',
            'homologacion' => 'sometimes|boolean',
        ]);

        $certContent = $request->file('cert')->get();
        $keyContent  = $request->file('key')->get();
        $passphrase  = $request->input('passphrase', '');

        // Se valida ANTES de guardar — mejor un 422 claro que un certificado
        // roto guardado en silencio que recién falla el día que se intenta
        // facturar de verdad.
        $certLeido = @openssl_x509_read($certContent);
        $keyLeida  = @openssl_get_privatekey($keyContent, $passphrase);
        if (!$certLeido || !$keyLeida) {
            return response()->json([
                'success' => false,
                'message' => 'El certificado o la clave no son válidos, o la contraseña de la clave es incorrecta.',
            ], 422);
        }

        $empresa->update([
            'arca_cert'         => $certContent,
            'arca_key'          => $keyContent,
            'arca_passphrase'   => $passphrase ?: null,
            'arca_punto_venta'  => $request->punto_venta,
            'arca_homologacion' => $request->boolean('homologacion', true),
        ]);

        return response()->json(['success' => true, 'empresa' => $empresa->fresh()]);
    }

    /**
     * DELETE /empresa/arca — borra el certificado de ARCA de la empresa
     * (vuelve a modo prueba, sin credenciales reales).
     */
    public function eliminarArca(): JsonResponse
    {
        $empresa = auth('api')->user()->empresa;
        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'No tenés una empresa asociada'], 400);
        }

        $empresa->update([
            'arca_cert'        => null,
            'arca_key'         => null,
            'arca_passphrase'  => null,
            'arca_punto_venta' => null,
        ]);

        return response()->json(['success' => true, 'empresa' => $empresa->fresh()]);
    }

    /**
     * GET /empresa/exportar-datos — backup completo de la empresa del usuario
     * autenticado en un .zip con un CSV por tabla, para que el dueño pueda
     * tener su propio respaldo o migrarse a otro sistema si quiere.
     */
    public function exportarDatos(): BinaryFileResponse
    {
        $empresa = auth('api')->user()->empresa;
        $empresaId = auth('api')->user()->empresa_id;

        $tablas = [
            'productos'          => Producto::where('empresa_id', $empresaId)->get(),
            'clientes'           => Cliente::where('empresa_id', $empresaId)->get(),
            'proveedores'        => Proveedor::where('empresa_id', $empresaId)->get(),
            'ventas'             => Venta::where('empresa_id', $empresaId)->get(),
            'compras'            => Compra::where('empresa_id', $empresaId)->get(),
            'movimientos_stock'  => MovimientoStock::where('empresa_id', $empresaId)->get(),
            'movimientos_puntos' => MovimientoPuntos::where('empresa_id', $empresaId)->get(),
        ];

        $zipPath = tempnam(sys_get_temp_dir(), 'export') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);

        foreach ($tablas as $nombre => $filas) {
            if ($filas->isEmpty()) {
                continue;
            }

            $csv = Writer::createFromString('');
            $csv->insertOne(array_keys($filas->first()->toArray()));
            foreach ($filas as $fila) {
                $csv->insertOne(array_values($fila->toArray()));
            }

            $zip->addFromString("{$nombre}.csv", $csv->toString());
        }

        $zip->close();

        $filename = 'backup-' . Str::slug($empresa->nombre ?: 'negocio') . '-' . now()->format('Y-m-d') . '.zip';

        return response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
