<?php

namespace Tests\Unit;

use App\Http\Controllers\Concerns\VerifiesMercadoPagoWebhook;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * La firma x-signature de Mercado Pago es lo único que impide que cualquiera
 * que conozca/adivine un payment_id le pegue al webhook público haciéndolo
 * procesar como si viniera de MP de verdad — se prueba en aislado (sin red,
 * sin DB) porque es la pieza de seguridad más crítica de todo el flujo de
 * pagos y el webhook real no es testeable de punta a punta sin mockear el
 * SDK completo de Mercado Pago.
 */
class VerifiesMercadoPagoWebhookTest extends TestCase
{
    private function firmaValida(Request $request): bool
    {
        $sujeto = new class {
            use VerifiesMercadoPagoWebhook;
        };
        $metodo = new ReflectionMethod($sujeto::class, 'firmaMercadoPagoValida');
        $metodo->setAccessible(true);
        return $metodo->invoke($sujeto, $request);
    }

    // env() en Laravel lee de $_ENV/$_SERVER (repositorio de phpdotenv, poblado
    // al bootear), no de getenv() en vivo — un putenv() a mitad de test no lo
    // actualiza. Hay que tocar $_ENV/$_SERVER directo para que env() lo vea.
    private function setSecreto(?string $valor): void
    {
        if ($valor === null) {
            unset($_ENV['MP_WEBHOOK_SECRET'], $_SERVER['MP_WEBHOOK_SECRET']);
            putenv('MP_WEBHOOK_SECRET');
            return;
        }
        $_ENV['MP_WEBHOOK_SECRET'] = $valor;
        $_SERVER['MP_WEBHOOK_SECRET'] = $valor;
        putenv("MP_WEBHOOK_SECRET={$valor}");
    }

    protected function tearDown(): void
    {
        $this->setSecreto(null);
        parent::tearDown();
    }

    public function test_sin_secreto_configurado_no_bloquea_nada(): void
    {
        $this->setSecreto(null);

        $request = Request::create('/api/suscripcion/webhook', 'POST');
        $this->assertTrue($this->firmaValida($request));
    }

    public function test_firma_valida_es_aceptada(): void
    {
        $this->setSecreto('un-secreto-de-prueba');

        $dataId = '123456789';
        $requestId = 'req-abc-123';
        $ts = (string) time();
        $manifest = 'id:' . strtolower($dataId) . ";request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'un-secreto-de-prueba');

        // Se pasa 'data.id' como parámetro GET explícito (no en la query string
        // cruda) porque parse_str de PHP mangling los puntos de nombres de
        // parámetro a guiones bajos — así se prueba exactamente la clave
        // literal que $request->query('data.id') busca.
        // Se setea el ParameterBag directo (no una query string cruda ni el
        // array $parameters de create()) porque ambos pasan por parse_str,
        // que mangling los puntos de nombres de parámetro a guiones bajos.
        $request = Request::create('/api/suscripcion/webhook', 'POST');
        $request->query->set('data.id', $dataId);
        $request->headers->set('x-signature', "ts={$ts},v1={$v1}");
        $request->headers->set('x-request-id', $requestId);

        $this->assertTrue($this->firmaValida($request));
    }

    public function test_firma_con_hash_alterado_es_rechazada(): void
    {
        $this->setSecreto('un-secreto-de-prueba');

        $dataId = '123456789';
        $requestId = 'req-abc-123';
        $ts = (string) time();

        // Se pasa 'data.id' como parámetro GET explícito (no en la query string
        // cruda) porque parse_str de PHP mangling los puntos de nombres de
        // parámetro a guiones bajos — así se prueba exactamente la clave
        // literal que $request->query('data.id') busca.
        // Se setea el ParameterBag directo (no una query string cruda ni el
        // array $parameters de create()) porque ambos pasan por parse_str,
        // que mangling los puntos de nombres de parámetro a guiones bajos.
        $request = Request::create('/api/suscripcion/webhook', 'POST');
        $request->query->set('data.id', $dataId);
        $request->headers->set('x-signature', "ts={$ts},v1=hashfalsificado");
        $request->headers->set('x-request-id', $requestId);

        $this->assertFalse($this->firmaValida($request));
    }

    public function test_firma_con_secreto_distinto_es_rechazada(): void
    {
        $this->setSecreto('el-secreto-correcto');

        $dataId = '123456789';
        $requestId = 'req-abc-123';
        $ts = (string) time();
        $manifest = 'id:' . strtolower($dataId) . ";request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'un-secreto-que-no-es-el-correcto');

        // Se pasa 'data.id' como parámetro GET explícito (no en la query string
        // cruda) porque parse_str de PHP mangling los puntos de nombres de
        // parámetro a guiones bajos — así se prueba exactamente la clave
        // literal que $request->query('data.id') busca.
        // Se setea el ParameterBag directo (no una query string cruda ni el
        // array $parameters de create()) porque ambos pasan por parse_str,
        // que mangling los puntos de nombres de parámetro a guiones bajos.
        $request = Request::create('/api/suscripcion/webhook', 'POST');
        $request->query->set('data.id', $dataId);
        $request->headers->set('x-signature', "ts={$ts},v1={$v1}");
        $request->headers->set('x-request-id', $requestId);

        $this->assertFalse($this->firmaValida($request));
    }

    public function test_sin_header_x_request_id_es_rechazada(): void
    {
        $this->setSecreto('un-secreto-de-prueba');

        $request = Request::create('/api/suscripcion/webhook?data.id=123', 'POST');
        $request->headers->set('x-signature', 'ts=123,v1=algo');

        $this->assertFalse($this->firmaValida($request));
    }
}
