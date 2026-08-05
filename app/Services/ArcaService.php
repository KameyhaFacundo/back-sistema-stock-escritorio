<?php

namespace App\Services;

use App\Models\Empresa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de facturación electrónica ARCA (ex AFIP), scopeado a UNA empresa
 * por instancia — cada empresa tiene su propio certificado, CUIT y punto de
 * venta (ver Empresa::arcaConfigurada()), nunca una cuenta compartida.
 *
 * Requiere:
 * - Certificado digital (.crt/.pem) + clave privada (.key/.pem) de la empresa
 * - CUIT del contribuyente (Empresa::cuit)
 * - Punto de venta habilitado en ARCA
 *
 * Modo test: si la empresa no configuró sus credenciales, genera facturas de
 * prueba con CAE ficticio.
 */
class ArcaService
{
    private ?string $cuit;
    private ?string $cert;
    private ?string $key;
    private ?string $passphrase;
    private bool $modoHomologacion;
    private bool $disponible = false;

    // Endpoints ARCA
    private const WSFE_HOMO   = 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL';
    private const WSFE_PROD   = 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL';
    private const WSAA_HOMO   = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL';
    private const WSAA_PROD   = 'https://wsaa.afip.gov.ar/ws/services/LoginCms?WSDL';

    // Tipos de comprobante
    public const TIPO_FACTURA_A = 1;
    public const TIPO_FACTURA_B = 6;
    public const TIPO_FACTURA_C = 11;
    public const TIPO_NOTA_CREDITO_A = 3;
    public const TIPO_NOTA_CREDITO_B = 8;
    public const TIPO_NOTA_CREDITO_C = 13;

    // Tipos de documento
    public const DOC_CUIT   = 80;
    public const DOC_DNI    = 96;
    public const DOC_CONSUMIDOR_FINAL = 99;

    // Conceptos
    public const CONCEPTO_PRODUCTOS = 1;
    public const CONCEPTO_SERVICIOS = 2;
    public const CONCEPTO_MIXTO     = 3;

    // Moneda
    public const MONEDA_PESOS = 'PES';
    public const MONEDA_DOLAR = 'DOL';

    public function __construct(Empresa $empresa)
    {
        $this->cuit             = $empresa->cuit;
        $this->cert             = $empresa->arca_cert;
        $this->key              = $empresa->arca_key;
        $this->passphrase       = $empresa->arca_passphrase;
        $this->modoHomologacion = (bool) $empresa->arca_homologacion;

        $this->disponible = $empresa->arcaConfigurada();
    }

    /**
     * Verifica si ARCA está configurada y disponible.
     */
    public function disponible(): bool
    {
        return $this->disponible;
    }

    /**
     * Tipo de Nota de Crédito que corresponde a un tipo de Factura dado — una
     * NC siempre tiene que ser de la misma letra que la factura que credita
     * (A acredita con NC A, B con NC B, C con NC C), ARCA la rechaza si no.
     */
    public static function tipoNotaCreditoPara(int $tipoFactura): int
    {
        return match ($tipoFactura) {
            self::TIPO_FACTURA_A => self::TIPO_NOTA_CREDITO_A,
            self::TIPO_FACTURA_C => self::TIPO_NOTA_CREDITO_C,
            default => self::TIPO_NOTA_CREDITO_B,
        };
    }

    /**
     * Emite una factura electrónica.
     * Si no hay credenciales, genera una factura de prueba.
     */
    public function emitirFactura(array $datos): array
    {
        if (!$this->disponible) {
            return $this->facturaPrueba($datos);
        }

        try {
            return $this->emitirFacturaReal($datos);
        } catch (\Exception $e) {
            Log::error('ARCA emitirFactura error: ' . $e->getMessage());
            $this->reportarError($e);
            return $this->facturaPrueba($datos);
        }
    }

    /**
     * Como emitirFactura(), pero para el flujo encolado (EmitirFacturaJob):
     * asume que ya se sabe que la empresa tiene ARCA configurado (el
     * llamador ya chequeó arcaConfigurada() antes de encolar), y a
     * diferencia de emitirFactura() NO amortigua un fallo de red cayendo a
     * modo prueba — deja la excepción subir para que el Job decida
     * (reintentar según backoff, o marcar la factura en estado=error si es
     * un rechazo de negocio, que llega como respuesta normal, no excepción).
     */
    public function emitirFacturaReal(array $datos): array
    {
        $ticket = $this->obtenerTicketAccesoRaw();

        $ultimoNumero = $this->consultarUltimoComprobanteRaw(
            $ticket['token'],
            $ticket['sign'],
            $datos['punto_venta'],
            $datos['tipo_comprobante'] ?? self::TIPO_FACTURA_B
        );

        return $this->solicitarCAE($ticket['token'], $ticket['sign'], $datos, $ultimoNumero + 1);
    }

    /**
     * Obtiene el último número de comprobante autorizado.
     */
    public function consultarUltimoComprobante(
        string $token, string $sign, int $puntoVenta, int $tipoComprobante
    ): int {
        try {
            return $this->consultarUltimoComprobanteRaw($token, $sign, $puntoVenta, $tipoComprobante);
        } catch (\Exception $e) {
            Log::warning('ARCA consultarUltimoComprobante error: ' . $e->getMessage());
            $this->reportarError($e);
            return 0;
        }
    }

    private function consultarUltimoComprobanteRaw(
        string $token, string $sign, int $puntoVenta, int $tipoComprobante
    ): int {
        return $this->conReintento(function () use ($token, $sign, $puntoVenta, $tipoComprobante) {
            $client = new \SoapClient($this->wsfeWsdl(), [
                'trace' => 1,
                'exceptions' => true,
                'soap_version' => SOAP_1_2,
                'connection_timeout' => 15,
            ]);

            $response = $client->FECompUltimoAutorizado([
                'Auth' => ['Token' => $token, 'Sign' => $sign, 'Cuit' => $this->cuit],
                'PtoVta' => $puntoVenta,
                'CbteTipo' => $tipoComprobante,
            ]);

            return (int) ($response->FECompUltimoAutorizadoResult->CbteNro ?? 0);
        });
    }

    /**
     * Solicita CAE a ARCA.
     */
    private function solicitarCAE(string $token, string $sign, array $datos, int $numero): array
    {
        $tipoComprobante = $datos['tipo_comprobante'] ?? self::TIPO_FACTURA_B;
        $puntoVenta      = $datos['punto_venta'] ?? 1;
        $fecha           = $datos['fecha'] ?? date('Ymd');
        $tipoDoc         = $datos['tipo_documento'] ?? self::DOC_CONSUMIDOR_FINAL;
        $nroDoc          = $datos['numero_documento'] ?? '0';

        $comprobante = [
            'CantReg'      => count($datos['items'] ?? []),
            'PtoVta'       => $puntoVenta,
            'CbteTipo'     => $tipoComprobante,
            'Concepto'     => $datos['concepto'] ?? self::CONCEPTO_PRODUCTOS,
            'DocTipo'      => $tipoDoc,
            'DocNro'       => $nroDoc,
            'CbteDesde'    => $numero,
            'CbteHasta'    => $numero,
            'CbteFch'      => $fecha,
            'ImpTotal'     => round($datos['total'] ?? 0, 2),
            'ImpTotConc'   => 0,
            'ImpNeto'      => round(($datos['neto'] ?? $datos['total'] ?? 0) / 1.21, 2),
            'ImpOpEx'      => 0,
            'ImpIVA'       => round(($datos['iva'] ?? 0), 2),
            'ImpTrib'      => 0,
            'FchServDesde' => $fecha,
            'FchServHasta' => $fecha,
            'FchVtoPago'   => $fecha,
            'MonId'        => $datos['moneda'] ?? self::MONEDA_PESOS,
            'MonCotiz'     => 1,
        ];

        // Comprobante asociado — obligatorio para toda Nota de Crédito (ARCA
        // la rechaza sin esto): apunta a la factura original que se credita.
        if (!empty($datos['comprobante_asociado'])) {
            $asoc = $datos['comprobante_asociado'];
            $comprobante['CbtesAsoc'] = ['CbteAsoc' => [[
                'Tipo'   => $asoc['tipo'],
                'PtoVta' => $asoc['punto_venta'],
                'Nro'    => $asoc['numero'],
            ]]];
        }

        // Agregar items con alícuotas de IVA
        if (!empty($datos['items'])) {
            $comprobante['Iva'] = [];
            foreach ($datos['items'] as $item) {
                $comprobante['Iva'][] = [
                    'Id'     => 5, // 21% IVA
                    'BaseImp' => round(($item['precio'] ?? 0) * ($item['cantidad'] ?? 1) / 1.21, 2),
                    'Importe' => round(($item['precio'] ?? 0) * ($item['cantidad'] ?? 1) * 0.21 / 1.21, 2),
                ];
            }
        }

        $response = $this->conReintento(function () use ($token, $sign, $comprobante) {
            $client = new \SoapClient($this->wsfeWsdl(), [
                'trace' => 1,
                'exceptions' => true,
                'soap_version' => SOAP_1_2,
                'connection_timeout' => 15,
            ]);

            return $client->FECAESolicitar([
                'Auth'       => ['Token' => $token, 'Sign' => $sign, 'Cuit' => $this->cuit],
                'FeCAEReq'   => ['FeCabReq' => $comprobante, 'FeDetReq' => ['FECAEDetRequest' => [$comprobante]]],
            ]);
        });

        $detalle = $response->FECAESolicitarResult->FeDetResp->FECAEDetResponse ?? null;
        $resultado = $detalle->Resultado ?? 'R';

        if ($resultado === 'A') {
            return [
                'success'         => true,
                'cae'             => $detalle->CAE,
                'vencimiento_cae' => $detalle->CAEFchVto,
                'numero'          => $numero,
                'punto_venta'     => $puntoVenta,
                'tipo_comprobante'=> $tipoComprobante,
                'fecha'           => $fecha,
                'total'           => $comprobante['ImpTotal'],
            ];
        }

        $errores = [];
        if (!empty($detalle->Observaciones)) {
            foreach ($detalle->Observaciones as $obs) {
                $errores[] = ($obs->Msg ?? 'Error desconocido');
            }
        }

        Log::error('ARCA CAE rechazado', ['errores' => $errores]);
        return ['success' => false, 'errores' => $errores];
    }

    /**
     * Obtiene token de acceso WSAA (Web Service de Autenticación y Autorización).
     *
     * El TA que devuelve ARCA es válido ~12hs, y volver a pedir uno mientras
     * el anterior sigue vigente hace que WSAA lo rechace ("El CEE ya posee
     * un TA válido para el acceso al WSN solicitado") — por eso se cachea
     * por empresa/entorno en vez de pedir uno nuevo en cada factura. Solo se
     * cachean tickets obtenidos con éxito: si ARCA falla, no queremos que un
     * `null` cacheado deje la facturación rota hasta que expire el cache.
     */
    private function obtenerTicketAcceso(): ?array
    {
        try {
            return $this->obtenerTicketAccesoRaw();
        } catch (\Exception $e) {
            Log::error('ARCA obtenerTicketAcceso error: ' . $e->getMessage());
            $this->reportarError($e);
            return null;
        }
    }

    private function obtenerTicketAccesoRaw(): array
    {
        $cacheKey = 'arca_ta:' . $this->cuit . ':' . ($this->modoHomologacion ? 'homo' : 'prod');
        $cacheado = Cache::get($cacheKey);
        if ($cacheado) {
            return $cacheado;
        }

        // Generar TRA (Ticket de Requerimiento de Acceso)
        $tra = $this->generarTRA();

        // Firmar TRA con el certificado
        $cms = $this->firmarCMS($tra);

        // Solicitar token a WSAA
        $response = $this->conReintento(function () use ($cms) {
            $client = new \SoapClient($this->wsaaWsdl(), [
                'trace' => 1,
                'exceptions' => true,
                'soap_version' => SOAP_1_2,
                'connection_timeout' => 15,
            ]);

            return $client->loginCms(['in0' => $cms]);
        });
        $xml = simplexml_load_string($response->loginCmsReturn);

        if (!$xml) {
            throw new \RuntimeException('WSAA no devolvió una respuesta XML válida');
        }

        $token = (string) ($xml->credentials->token ?? '');
        $sign  = (string) ($xml->credentials->sign ?? '');

        if (!$token || !$sign) {
            throw new \RuntimeException('WSAA no devolvió token/sign');
        }

        $ticket = ['token' => $token, 'sign' => $sign];
        // Margen conservador por debajo de las ~12hs reales de validez.
        Cache::put($cacheKey, $ticket, now()->addHours(11));

        return $ticket;
    }

    private function generarTRA(): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElement('loginTicketRequest');
        $root->setAttribute('version', '1.0');
        $dom->appendChild($root);

        $header = $dom->createElement('header');
        $root->appendChild($header);

        $header->appendChild($dom->createElement('uniqueId', time()));
        $header->appendChild($dom->createElement('generationTime', 
            date('Y-m-d\TH:i:s', time() - 60) . '-03:00'
        ));
        $header->appendChild($dom->createElement('expirationTime',
            date('Y-m-d\TH:i:s', time() + 60) . '-03:00'
        ));

        $service = $dom->createElement('service', 'wsfe');
        $root->appendChild($service);

        return $dom->saveXML();
    }

    private function firmarCMS(string $tra): string
    {
        // openssl_x509_read()/openssl_get_privatekey() aceptan el contenido
        // PEM directo — no hace falta que el cert/key vivan en un archivo.
        $cert       = openssl_x509_read($this->cert);
        $privateKey = openssl_get_privatekey($this->key, $this->passphrase ?: '');

        if (!$cert || !$privateKey) {
            throw new \Exception('No se pudo leer el certificado o clave privada');
        }

        $tmpTra  = tempnam(sys_get_temp_dir(), 'tra_');
        $tmpCms  = tempnam(sys_get_temp_dir(), 'cms_');
        file_put_contents($tmpTra, $tra);

        // WSAA necesita el CMS con el TRA embebido (no detached) para poder
        // leerlo del lado de ARCA — PKCS7_DETACHED dejaría solo la firma.
        openssl_pkcs7_sign(
            $tmpTra, $tmpCms,
            $cert, [$privateKey, $this->passphrase ?: ''],
            [], PKCS7_NOATTR | PKCS7_BINARY
        );

        $cms = file_get_contents($tmpCms);
        unlink($tmpTra); unlink($tmpCms);

        // Extraer solo la parte firmada
        $cms = preg_replace('/.*Content-Type:.*?\n\n/s', '', $cms);
        $cms = trim(str_replace("\n", '', $cms));

        return $cms;
    }

    private function facturaPrueba(array $datos): array
    {
        $numero = ($datos['ultimo_numero'] ?? 0) + 1;
        return [
            'success'         => true,
            'cae'             => '99999999999999',
            'vencimiento_cae' => date('Ymd', strtotime('+10 days')),
            'numero'          => $numero,
            'punto_venta'     => $datos['punto_venta'] ?? 1,
            'tipo_comprobante'=> $datos['tipo_comprobante'] ?? self::TIPO_FACTURA_B,
            'fecha'           => $datos['fecha'] ?? date('Ymd'),
            'total'           => $datos['total'] ?? 0,
            'modo_prueba'     => true,
        ];
    }

    private function wsfeWsdl(): string
    {
        return $this->modoHomologacion ? self::WSFE_HOMO : self::WSFE_PROD;
    }

    private function wsaaWsdl(): string
    {
        return $this->modoHomologacion ? self::WSAA_HOMO : self::WSAA_PROD;
    }

    /**
     * Reintenta una sola vez ante una falla de transporte/protocolo SOAP
     * (timeout, conexión reseteada, etc.) — no reintenta errores de negocio
     * (ej. CAE rechazado), esos vienen como respuesta normal, no excepción.
     */
    private function conReintento(callable $fn, int $intentos = 2)
    {
        for ($intento = 1; $intento <= $intentos; $intento++) {
            try {
                return $fn();
            } catch (\SoapFault $e) {
                if ($intento >= $intentos) {
                    throw $e;
                }
                usleep(500_000);
            }
        }
    }

    /**
     * Manda a Sentry las fallas reales de ARCA (no la simple ausencia de
     * configuración) — sin esto, una empresa podía quedar semanas emitiendo
     * facturas de prueba con CAE ficticio sin que nadie del equipo se
     * enterara, porque el único rastro era un Log::error perdido.
     */
    private function reportarError(\Throwable $e): void
    {
        if (app()->bound('sentry') && config('sentry.dsn')) {
            app('sentry')->captureException($e);
        }
    }
}
