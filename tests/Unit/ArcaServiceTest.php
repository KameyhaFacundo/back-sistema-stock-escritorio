<?php

namespace Tests\Unit;

use App\Models\Empresa;
use App\Services\ArcaService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ArcaService firma el TRA a mano con openssl_pkcs7_sign() — es la parte
 * más frágil de la integración (ya generó un bug real en producción por
 * fuera de este archivo) y no depende de red, así que se puede probar sin
 * pegarle a ARCA de verdad usando un par cert/key autofirmado.
 */
class ArcaServiceTest extends TestCase
{
    /**
     * openssl_pkey_new()/csr_new()/csr_sign() necesitan un openssl.cnf con
     * sección [req] — usamos uno mínimo propio en vez del global del SO
     * para que el test no dependa de cómo esté configurado cada máquina.
     */
    private function generarCertYKey(): array
    {
        $cnf = tempnam(sys_get_temp_dir(), 'cnf_');
        file_put_contents($cnf, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
        $config = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $pkey = openssl_pkey_new($config);
        $csr  = openssl_csr_new(['CN' => 'Empresa de prueba'], $pkey, $config);
        $cert = openssl_csr_sign($csr, null, $pkey, 365, $config);

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $keyPem, null, $config);
        unlink($cnf);

        return [$certPem, $keyPem];
    }

    private function empresaSinConfigurar(): Empresa
    {
        return new Empresa(['cuit' => '20304050607']);
    }

    private function empresaConfigurada(bool $homologacion = true): Empresa
    {
        [$cert, $key] = $this->generarCertYKey();

        return new Empresa([
            'cuit'              => '20304050607',
            'arca_cert'         => $cert,
            'arca_key'          => $key,
            'arca_punto_venta'  => 1,
            'arca_homologacion' => $homologacion,
        ]);
    }

    public function test_no_esta_disponible_sin_certificado(): void
    {
        $arca = new ArcaService($this->empresaSinConfigurar());

        $this->assertFalse($arca->disponible());
    }

    public function test_esta_disponible_con_cuit_cert_key_y_punto_de_venta(): void
    {
        $arca = new ArcaService($this->empresaConfigurada());

        $this->assertTrue($arca->disponible());
    }

    public function test_emite_factura_de_prueba_si_no_esta_configurada(): void
    {
        $arca = new ArcaService($this->empresaSinConfigurar());

        $resultado = $arca->emitirFactura(['total' => 100, 'items' => [], 'ultimo_numero' => 5]);

        $this->assertTrue($resultado['success']);
        $this->assertTrue($resultado['modo_prueba']);
        $this->assertEquals('99999999999999', $resultado['cae']);
        $this->assertEquals(6, $resultado['numero']);
    }

    public function test_usa_wsdl_de_homologacion_por_defecto(): void
    {
        $arca = new ArcaService($this->empresaConfigurada(homologacion: true));

        $wsfe = new ReflectionMethod(ArcaService::class, 'wsfeWsdl');
        $wsaa = new ReflectionMethod(ArcaService::class, 'wsaaWsdl');

        $this->assertStringContainsString('wswhomo', $wsfe->invoke($arca));
        $this->assertStringContainsString('wsaahomo', $wsaa->invoke($arca));
    }

    public function test_usa_wsdl_de_produccion_cuando_la_empresa_no_esta_en_homologacion(): void
    {
        $arca = new ArcaService($this->empresaConfigurada(homologacion: false));

        $wsfe = new ReflectionMethod(ArcaService::class, 'wsfeWsdl');
        $wsaa = new ReflectionMethod(ArcaService::class, 'wsaaWsdl');

        $this->assertStringNotContainsString('homo', $wsfe->invoke($arca));
        $this->assertStringNotContainsString('homo', $wsaa->invoke($arca));
    }

    public function test_firma_cms_con_un_par_cert_key_valido(): void
    {
        $arca = new ArcaService($this->empresaConfigurada());

        $generarTra = new ReflectionMethod(ArcaService::class, 'generarTRA');
        $firmarCms  = new ReflectionMethod(ArcaService::class, 'firmarCMS');

        $tra = $generarTra->invoke($arca);
        $cms = $firmarCms->invoke($arca, $tra);

        $this->assertNotEmpty($cms);
    }

    public function test_firma_cms_lanza_excepcion_si_el_certificado_no_es_valido(): void
    {
        $empresa = $this->empresaSinConfigurar();
        $empresa->arca_cert = 'no-es-un-certificado';
        $empresa->arca_key  = 'no-es-una-clave';
        $empresa->arca_punto_venta = 1;
        $arca = new ArcaService($empresa);

        $generarTra = new ReflectionMethod(ArcaService::class, 'generarTRA');
        $firmarCms  = new ReflectionMethod(ArcaService::class, 'firmarCMS');
        $tra = $generarTra->invoke($arca);

        $this->expectException(\Exception::class);
        $firmarCms->invoke($arca, $tra);
    }

    /**
     * Empresa "configurada" (cuit + cert + key + punto de venta presentes,
     * así que disponible() = true) pero con un cert/key que no sirve para
     * firmar de verdad — esto hace fallar firmarCMS() ANTES de que
     * obtenerTicketAccesoRaw() llegue a intentar ninguna llamada de red, así
     * que sirve para probar el camino de excepción sin depender de internet
     * ni de ARCA de verdad (mismo criterio que el resto de este archivo).
     */
    private function empresaConfiguradaConCertInvalido(): Empresa
    {
        return new Empresa([
            'cuit'              => '20304050607',
            'arca_cert'         => 'no-es-un-certificado',
            'arca_key'          => 'no-es-una-clave',
            'arca_punto_venta'  => 1,
            'arca_homologacion' => true,
        ]);
    }

    public function test_emitir_factura_real_propaga_la_excepcion_en_vez_de_caer_a_modo_prueba(): void
    {
        $arca = new ArcaService($this->empresaConfiguradaConCertInvalido());

        $this->expectException(\Exception::class);
        $arca->emitirFacturaReal(['punto_venta' => 1, 'tipo_comprobante' => ArcaService::TIPO_FACTURA_B, 'total' => 100, 'items' => []]);
    }

    public function test_emitir_factura_sigue_cayendo_a_modo_prueba_ante_una_falla_real(): void
    {
        // emitirFactura() (a diferencia de emitirFacturaReal()) es el camino
        // síncrono que todavía usa el controller para empresas SIN ARCA
        // configurado — tiene que seguir sin explotar nunca.
        $arca = new ArcaService($this->empresaConfiguradaConCertInvalido());

        $resultado = $arca->emitirFactura(['punto_venta' => 1, 'total' => 100, 'items' => [], 'ultimo_numero' => 3]);

        $this->assertTrue($resultado['success']);
        $this->assertTrue($resultado['modo_prueba']);
        $this->assertEquals('99999999999999', $resultado['cae']);
    }
}
