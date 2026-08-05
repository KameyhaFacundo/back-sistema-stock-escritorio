<?php

namespace App\Jobs;

use App\Models\Factura;
use App\Services\ArcaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Igual que EmitirFacturaJob, pero para una nota de crédito que quedó
 * pendiente (ver FacturaController::emitirNotaCredito()). El comprobante
 * asociado (la factura que se está acreditando) se reconstruye acá desde la
 * relación en vez de guardarse aparte — ya vive en id_comprobante_asociado.
 */
class EmitirNotaCreditoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $facturaId) {}

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addDays(3);
    }

    public function handle(): void
    {
        $notaCredito = Factura::find($this->facturaId);

        if (!$notaCredito || $notaCredito->estado !== 'pendiente') {
            return;
        }

        $original = $notaCredito->comprobanteAsociado;
        if (!$original) {
            $notaCredito->update([
                'estado'        => 'error',
                'error_mensaje' => 'No se encontró la factura original asociada.',
            ]);
            return;
        }

        $arca = new ArcaService($notaCredito->empresa);

        $datos = [
            'punto_venta'      => $notaCredito->punto_venta,
            'tipo_comprobante' => $notaCredito->tipo_comprobante,
            'tipo_documento'   => $notaCredito->tipo_documento,
            'numero_documento' => $notaCredito->numero_documento,
            'fecha'            => $notaCredito->fecha,
            'total'            => (float) $notaCredito->total,
            'neto'             => (float) $notaCredito->neto,
            'iva'              => (float) $notaCredito->iva,
            'items'            => $notaCredito->items ?? [],
            'comprobante_asociado' => [
                'tipo'        => $original->tipo_comprobante,
                'punto_venta' => $original->punto_venta,
                'numero'      => $original->numero,
            ],
        ];

        // Excepción de red: sube sin atrapar, Laravel reintenta solo.
        $resultado = $arca->emitirFacturaReal($datos);

        if (empty($resultado['success'])) {
            $notaCredito->update([
                'estado'        => 'error',
                'error_mensaje' => implode('; ', $resultado['errores'] ?? ['Error desconocido al emitir la nota de crédito']),
            ]);
            return;
        }

        $notaCredito->update([
            'numero'          => $resultado['numero'],
            'cae'             => $resultado['cae'],
            'vencimiento_cae' => $resultado['vencimiento_cae'],
            'estado'          => 'emitida',
            'error_mensaje'   => null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('EmitirNotaCreditoJob agotó los reintentos: ' . $e->getMessage(), ['factura_id' => $this->facturaId]);

        $notaCredito = Factura::find($this->facturaId);
        if ($notaCredito && $notaCredito->estado === 'pendiente') {
            $notaCredito->update([
                'estado'        => 'error',
                'error_mensaje' => 'No se pudo conectar con ARCA después de varios días de intentos.',
            ]);
        }
    }
}
