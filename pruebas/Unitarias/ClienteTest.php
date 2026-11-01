<?php

declare(strict_types=1);

namespace Trevol\Pruebas\Unitarias;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trevol\Exception\ConfigurationException;
use Trevol\Generado\Tablas;
use Trevol\Pruebas\Banco;
use Trevol\Trevol;

final class ClienteTest extends TestCase
{
    public function testAuthorizationTrevolVersionFijaUserAgentYNingunaXTrevol(): void
    {
        $b = new Banco([Banco::json(200, Banco::DOCUMENTO)]);
        $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
        $c = $b->peticiones[0]['cabeceras'];
        $this->assertSame('Bearer ' . Banco::LLAVE_TEST, $c['authorization']);
        $this->assertSame('2026-10-22', $c['trevol-version']);
        $this->assertSame(Tablas::API_VERSION, '2026-10-22');
        $this->assertMatchesRegularExpression('#^trevol-php/' . preg_quote(Trevol::VERSION, '#') . ' \(php \d+\.\d+\.\d+[^;]*; \+https://platform\.trevol\.si/docs\)$#', $c['user-agent']);
        $this->assertSame('application/json, application/problem+json', $c['accept']);
        foreach (array_keys($c) as $k) {
            $this->assertStringStartsNotWith('x-trevol-', $k);
        }
        $this->assertSame([], array_intersect(array_keys($c), ['trevol-empresa', 'trevol-entorno', 'trevol-escenario']));
    }

    public function testApiVersionEsConfigurableYViajaEnCadaPeticion(): void
    {
        $b = new Banco([Banco::json(200, Banco::DOCUMENTO)], opciones: ['apiVersion' => '2026-10-21']);
        $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
        $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
        $this->assertSame(['2026-10-21', '2026-10-21'], array_map(static fn ($p) => $p['cabeceras']['trevol-version'], $b->peticiones));
    }

    public function testElAcceptDeUnaDescargaEsElDelArchivoYLosBytesLleganIntactos(): void
    {
        $binario = pack('C*', 37, 80, 68, 70, 0, 255, 1, 2);
        $b = new Banco([new Response(200, ['Content-Type' => 'application/pdf'], $binario)]);
        $pdf = $b->trevol->invoices->downloadInvoicePdf(Banco::ID_DOC);
        $this->assertSame('application/pdf, application/problem+json', $b->peticiones[0]['cabeceras']['accept']);
        $this->assertInstanceOf(\SplFileObject::class, $pdf);
        $this->assertSame($binario, file_get_contents($pdf->getPathname()));
        $this->assertSame('/v1/invoices/' . Banco::ID_DOC . '/pdf', $b->peticiones[0]['ruta']);
    }

    public function testLaLlaveLiveSeUsaTalCualYLoDiceElEntorno(): void
    {
        $b = new Banco([Banco::json(200, Banco::DOCUMENTO)], Banco::LLAVE_LIVE);
        $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
        $this->assertSame('Bearer ' . Banco::LLAVE_LIVE, $b->peticiones[0]['cabeceras']['authorization']);
        $this->assertSame('live', $b->trevol->environment);
    }

    public function testElEntornoSeDeduceDelPrefijo(): void
    {
        $this->assertSame('test', (new Trevol(Banco::LLAVE_TEST))->environment);
        $this->assertSame('live', (new Trevol(Banco::LLAVE_LIVE))->environment);
    }

    public function testLeeTrevolApiKeySiNoSePasa(): void
    {
        putenv('TREVOL_API_KEY=' . Banco::LLAVE_LIVE);
        try {
            $this->assertSame('live', (new Trevol())->environment);
            putenv('TREVOL_API_KEY=');
            $this->expectException(ConfigurationException::class);
            new Trevol();
        } finally {
            putenv('TREVOL_API_KEY');
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function llavesMalas(): array
    {
        $a = str_repeat('a1', 32);

        return [
            'corta' => ['tvl_test_abc'], 'prefijo malo' => ["tvl_prod_{$a}"], 'otro proveedor' => ["sk_live_{$a}"],
            'mayúsculas' => ['tvl_test_' . str_repeat('A1', 32)], 'larga' => ['tvl_test_' . str_repeat('a1', 33)],
            'blancos' => ['   '], 'no hex' => ['tvl_test_' . str_repeat('zz', 32)],
        ];
    }

    #[DataProvider('llavesMalas')]
    public function testMalFormadaEsConfigurationExceptionSinRedYSinLaLlaveEnElMensaje(string $mala): void
    {
        try {
            new Trevol($mala);
            $this->fail('debía lanzar');
        } catch (ConfigurationException $e) {
            if (trim($mala) !== '') {
                $this->assertStringNotContainsString(trim($mala), $e->getMessage() . (string) $e);
            }
            // #[\SensitiveParameter]: el rastro de la pila muestra Object(SensitiveParameterValue), no la llave (el único cuadro con ella es el de esta prueba).
            $this->assertStringContainsString('Object(SensitiveParameterValue)', $e->getTraceAsString());
        }
    }

    public function testEnvironmentQueNoCoincideEsConfigurationExceptionAntesDeLlamar(): void
    {
        try {
            new Trevol(Banco::LLAVE_TEST, environment: 'live');
            $this->fail('debía lanzar');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('live', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        new Trevol(Banco::LLAVE_LIVE, environment: 'test');
    }

    public function testElEntornoCoincidenteNoLanza(): void
    {
        $this->assertSame('test', (new Trevol(Banco::LLAVE_TEST, environment: 'test'))->environment);
    }

    public function testNiElVarDumpNiElTraceMuestranLaLlave(): void
    {
        $t = new Trevol(Banco::LLAVE_TEST);
        ob_start();
        var_dump($t);
        print_r($t);
        $salida = (string) ob_get_clean();
        $this->assertStringNotContainsString(str_repeat('a1', 32), $salida);
    }

    public function testCadaEtiquetaDeOpenapiTieneSuPropiedadEnElCliente(): void
    {
        $etiquetas = Banco::datos('tags.json');
        $this->assertSame($etiquetas, Tablas::ETIQUETAS);
        $t = new Trevol(Banco::LLAVE_TEST);
        foreach ($etiquetas as $e) {
            $this->assertTrue(property_exists($t, lcfirst($e)), $e); // si la API suma un recurso, esto falla hasta agregarlo
        }
    }

    public function testCadaOperationIdExisteComoMetodoEnSuRecurso(): void
    {
        $t = new Trevol(Banco::LLAVE_TEST);
        foreach (Tablas::OPERACIONES as $o) {
            $this->assertTrue(method_exists($t->{lcfirst($o['recurso'])}, $o['operationId']), $o['operationId']);
        }
    }
}
