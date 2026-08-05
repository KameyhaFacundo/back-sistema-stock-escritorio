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
 * Termina de emitir una factura que quedó en estado "pendiente" porque ARCA
 * no estaba disponible en el momento de la venta (ver
 * FacturaController::emitir()). Si ARCA sigue sin responder, Laravel
 * reintenta solo según backoff()/retryUntil() — no hay que hacer nada
 * manual, se resuelve sola en cuanto vuelva la conexión.
 */
class EmitirFacturaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $facturaId) {}

    public function backoff(): array
    {
        // 1, 5, 15, 30 min — después repite el último (se corta un intento
        // por segundo no tiene sentido si lo que falta es señal, no un pico).
        return [60, 300, 900, 1800];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addDays(3);
    }

    public function handle(): void
    {
        $factura = Factura::find($this->facturaId);

        // Idempotencia: si ya se resolvió (por otra corrida del job, o a
        // mano) no hay nada más que hacer — mismo criterio que
        // PagoPointController con sus "intentos".
        if (!$factura || $factura->estado !== 'pendiente') {
            return;
        }

        $arca = new ArcaService($factura->empresa);

        $datos = [
            'punto_venta'      => $factura->punto_venta,
            'tipo_comprobante' => $factura->tipo_comprobante,
            'tipo_documento'   => $factura->tipo_documento,
            'numero_documento' => $factura->numero_documento,
            'fecha'            => $factura->fecha,
            'total'            => (float) $factura->total,
            'neto'             => (float) $factura->neto,
            'iva'              => (float) $factura->iva,
            'items'            => $factura->items ?? [],
        ];

        // Excepción de red (SoapFault, WSAA sin responder, etc.): no se
        // atrapa acá a propósito — sube y Laravel la reintenta solo según
        // backoff()/retryUntil() de arriba.
        $resultado = $arca->emitirFacturaReal($datos);

        if (empty($resultado['success'])) {
            // Rechazo de negocio (CUIT inválido, etc.) — reintentar sin
            // cambiar nada no lo va a arreglar solo.
            $factura->update([
                'estado'        => 'error',
                'error_mensaje' => implode('; ', $resultado['errores'] ?? ['Error desconocido al emitir la factura']),
            ]);
            return;
        }

        $factura->update([
            'numero'          => $resultado['numero'],
            'cae'             => $resultado['cae'],
            'vencimiento_cae' => $resultado['vencimiento_cae'],
            'estado'          => 'emitida',
            'error_mensaje'   => null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('EmitirFacturaJob agotó los reintentos: ' . $e->getMessage(), ['factura_id' => $this->facturaId]);

        $factura = Factura::find($this->facturaId);
        if ($factura && $factura->estado === 'pendiente') {
            $factura->update([
                'estado'        => 'error',
                'error_mensaje' => 'No se pudo conectar con ARCA después de varios días de intentos.',
            ]);
        }
    }
}
