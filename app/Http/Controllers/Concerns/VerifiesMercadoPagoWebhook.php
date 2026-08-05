<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait VerifiesMercadoPagoWebhook
{
    /**
     * Valida la firma x-signature que Mercado Pago manda en cada webhook
     * (algoritmo documentado: HMAC-SHA256 de un manifest con la clave secreta
     * de la integración, no el access token). Sin esto, cualquiera que
     * conozca o adivine un payment_id real puede pegarle a este endpoint
     * público y hacerlo procesar como si viniera de MP.
     *
     * Si MP_WEBHOOK_SECRET no está configurado (todavía no se cargó en el
     * dashboard de MP — típico en desarrollo local) no bloquea nada, mismo
     * criterio que el resto de las claves opcionales del proyecto (ver
     * sentry.js / VITE_SENTRY_DSN del front).
     */
    protected function firmaMercadoPagoValida(Request $request): bool
    {
        $secret = env('MP_WEBHOOK_SECRET');
        if (!$secret) {
            return true;
        }

        $signatureHeader = $request->header('x-signature');
        $requestId = $request->header('x-request-id');
        $dataId = $request->query('data.id') ?? $request->input('data.id');

        if (!$signatureHeader || !$requestId || !$dataId) {
            return false;
        }

        $ts = null;
        $v1 = null;
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, [null, null]);
            if ($key === 'ts') $ts = $value;
            if ($key === 'v1') $v1 = $value;
        }

        if (!$ts || !$v1) {
            return false;
        }

        // El id en el manifest va en minúsculas si tiene letras (docs de MP) —
        // los payment_id son numéricos así que esto es un no-op en la práctica.
        $manifest = 'id:' . strtolower($dataId) . ";request-id:{$requestId};ts:{$ts};";
        $hash = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($hash, $v1);
    }
}
