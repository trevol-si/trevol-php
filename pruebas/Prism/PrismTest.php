<?php

declare(strict_types=1);

namespace Trevol\Pruebas\Prism;

use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Trevol\Exception\ConflictException;
use Trevol\Exception\PreconditionException;
use Trevol\Exception\RateLimitException;
use Trevol\Exception\TrevolException;
use Trevol\Trevol;

/**
 * Contra Prism (servidor simulado desde docs/api/openapi.json, docs/api/43 §2.10.2):
 *
 *     sdks/herramientas/prism.sh -- bash -c 'cd sdks/php && vendor/bin/phpunit --testsuite prism'
 *
 * Prism valida cada petición contra la especificación PÚBLICA: si el SDK serializa mal un cuerpo, olvida una cabecera o la Idempotency-Key,
 * responde 4xx y la prueba falla (el motivo viene en la cabecera `sl-violations`, que la excepción conserva).
 */
final class PrismTest extends TestCase
{
    private static string $url;
    private static string $llave;
    /** Una llave de administración inventada con el formato correcto (Prism solo exige que haya Authorization). */
    private const LLAVE_ADMIN = 'tva_test_c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4';
    private const EMPRESA = 'com_3f0c9a1e5b2d4c7f8a6e1d2c3b4a5f60';

    public static function setUpBeforeClass(): void
    {
        self::$url = (string) getenv('TREVOL_PRISM_URL');
        self::$llave = getenv('TREVOL_API_KEY') ?: 'tvl_test_' . str_repeat('a1', 32);
    }

    protected function setUp(): void
    {
        if (self::$url === '') {
            $this->markTestSkipped("necesita Prism: sdks/herramientas/prism.sh -- bash -c 'cd sdks/php && vendor/bin/phpunit --testsuite prism'");
        }
    }

    private function cliente(?string $prefer = null, int $reintentos = 0, ?array $internos = null, ?string $llave = null, ?string $company = null): Trevol
    {
        $handler = null;
        if ($prefer !== null) {
            $base = Utils::chooseHandler();
            $handler = static fn (RequestInterface $r, array $o) => $base($r->withHeader('Prefer', $prefer), $o);
        }

        return new Trevol($llave ?? self::$llave, maxRetries: $reintentos, baseUrl: self::$url, handler: $handler, internos: $internos, company: $company);
    }

    /** @return mixed el cuerpo de ejemplo de la especificación */
    private static function ejemplo(string $op, ?string $nombre = null): mixed
    {
        $ops = json_decode(file_get_contents(__DIR__ . '/../datos/operaciones.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($ops as $o) {
            if ($o['operationId'] === $op) {
                foreach ($o['ejemplos'] as $e) {
                    if ($nombre === null || $e['nombre'] === $nombre) {
                        return $e['valor'];
                    }
                }
            }
        }
        throw new \LogicException("no hay ejemplo {$nombre} de {$op}");
    }

    /** @template T @param callable(): T $f @return T */
    private function conViolaciones(callable $f): mixed
    {
        try {
            return $f();
        } catch (TrevolException $e) {
            $v = $e->getResponseHeaders()['Sl-Violations'][0] ?? $e->getResponseHeaders()['sl-violations'][0] ?? null;
            if ($v !== null) {
                $this->fail("Prism rechazó la petición ({$e->getStatus()}): {$v}");
            }
            throw $e;
        }
    }

    public function testCreaUnaFactura201ConElCuerpoDeEjemplo(): void
    {
        $t = $this->cliente();
        $doc = $this->conViolaciones(fn () => $t->invoices->createInvoice(self::ejemplo('createInvoice')));
        $this->assertSame('invoice', $doc->getObject());
    }

    public function testCreaUnaNotaCreditoYCalculaUnPreview(): void
    {
        $t = $this->cliente();
        $nc = $this->conViolaciones(fn () => $t->creditNotes->createCreditNote(self::ejemplo('createCreditNote')));
        $p = $this->conViolaciones(fn () => $t->invoices->previewInvoice(self::ejemplo('previewInvoice')));
        $this->assertNotNull($nc);
        $this->assertSame('preview', $p->getObject());
        $this->assertNotNull($p->getTotals());
    }

    public function testCorrigeUnaFacturaRechazadaConPut(): void
    {
        $t = $this->cliente();
        $this->assertNotNull($this->conViolaciones(fn () => $t->invoices->correctInvoice('inv_' . str_repeat('0', 32), self::ejemplo('correctInvoice'))));
    }

    public function testListaFacturasYEventosEIteraConTopeDe3(): void
    {
        $t = $this->cliente();
        foreach ([fn (?string $c) => $t->invoices->listInvoices(2, $c), fn (?string $c) => $t->events->listEvents(25, $c)] as $pagina) {
            $vistos = [];
            $this->conViolaciones(function () use ($t, $pagina, &$vistos) {
                foreach ($t->paginate($pagina) as $x) {
                    $vistos[] = $x->getId();
                    if (count($vistos) === 3) { // Prism repite siempre la misma página con has_more=true (C8): el tope es de la prueba
                        break;
                    }
                }
            });
            $this->assertNotEmpty($vistos);
        }
    }

    public function testDescargaPdfXmlZipYRespuestaDianLosBytesLleganYElTipoEsElCorrecto(): void
    {
        $t = $this->cliente();
        $id = 'inv_' . str_repeat('0', 32);
        foreach (['downloadInvoicePdf', 'downloadInvoiceXml', 'downloadInvoiceZip', 'downloadInvoiceDianResponse'] as $op) {
            $archivo = $this->conViolaciones(fn () => $t->invoices->$op($id));
            $this->assertInstanceOf(\SplFileObject::class, $archivo, $op);
            $this->assertGreaterThan(0, filesize($archivo->getPathname()), $op);
        }
        // El tipo: la respuesta cruda de la variante WithHttpInfo.
        [, $estado, $cabeceras] = $t->invoices->downloadInvoicePdfWithHttpInfo($id);
        $this->assertSame(200, $estado);
        $this->assertStringContainsString('application/pdf', implode(',', array_change_key_case($cabeceras)['content-type'] ?? []));
    }

    public function testPreferCode422SobreUnEventoRadianEsPreconditionExceptionTacitAcceptanceNotDue(): void
    {
        $t = $this->cliente('code=422, example=tacita');
        try {
            $t->radianEvents->createRadianEvent(self::ejemplo('createRadianEvent', 'tacita'));
            $this->fail('debía lanzar');
        } catch (PreconditionException $e) {
            $this->assertSame('tacit_acceptance_not_due', $e->getTrevolCode());
            $this->assertSame(422, $e->getStatus());
        }
    }

    public function testPreferCode409SobreUnEventoRadianEsConflictException(): void
    {
        $t = $this->cliente('code=409, example=duplicado');
        try {
            $t->radianEvents->createRadianEvent(self::ejemplo('createRadianEvent', 'acuse'));
            $this->fail('debía lanzar');
        } catch (ConflictException $e) {
            $this->assertSame(409, $e->getStatus());
            $this->assertStringStartsWith('rev_', $e->getProblem()['existing_id']);
        }
    }

    public function testPreferCode429EsUnaExcepcionConStatus429RetryableYLaClave(): void
    {
        $t = $this->cliente('code=429');
        try {
            $t->invoices->createInvoice(self::ejemplo('createInvoice'));
            $this->fail('debía lanzar');
        } catch (TrevolException $e) {
            $this->assertSame(429, $e->getStatus());
            $this->assertTrue($e->isRetryable());
            $this->assertNotEmpty($e->getIdempotencyKey());
        }
    }

    public function testCreaUnEndpointDeWebhooksYMandaLaPrueba202(): void
    {
        $t = $this->cliente();
        $ep = $this->conViolaciones(fn () => $t->webhookEndpoints->createWebhookEndpoint(self::ejemplo('createWebhookEndpoint')));
        $entrega = $this->conViolaciones(fn () => $t->webhookEndpoints->testWebhookEndpoint('we_' . str_repeat('0', 32)));
        $this->assertSame('webhook_endpoint', $ep->getObject());
        $this->assertNotNull($entrega);
    }

    public function testCompanyYAcquirerDevuelvenModelosNoVoid(): void
    {
        $t = $this->cliente();
        $c = $this->conViolaciones(fn () => $t->general->retrieveCompany());
        $a = $this->conViolaciones(fn () => $t->general->retrieveDianAcquirer('31', '900373591'));
        $this->assertSame('company', $c->getObject());
        $this->assertSame('acquirer', $a->getObject());
    }

    public function testReintentaContraPrismConLaMismaClaveYAgotaConElErrorDel503(): void
    {
        $esperas = [];
        $t = $this->cliente('code=503', 1, ['dormir' => static function (float $s) use (&$esperas): void { $esperas[] = $s; }]);
        try {
            $t->invoices->createInvoice(self::ejemplo('createInvoice'), 'PRUEBA-PRISM-1');
            $this->fail('debía lanzar');
        } catch (TrevolException $e) {
            $this->assertSame(503, $e->getStatus());
            $this->assertSame('PRUEBA-PRISM-1', $e->getIdempotencyKey());
            $this->assertCount(1, $esperas);
        }
    }

    public function testLlaveDeAdministracionLeeLaCuentaSusEmpresasYLoDeTodas(): void
    {
        $t = $this->cliente(llave: self::LLAVE_ADMIN);
        $this->assertSame('account', $t->keyType);
        $info = $this->conViolaciones(fn () => $t->account->retrieveAccount());
        $this->assertSame('account_key_info', $info->getObject());
        $this->assertStringStartsWith('acct_', $info->getAccount()->getId());
        $e = $this->conViolaciones(fn () => $t->account->retrieveAccountCompany(self::EMPRESA));
        $this->assertSame('company', $e->getObject());
        $listas = [
            fn (?string $c) => $t->account->listAccountCompanies(limit: 2, starting_after: $c, status: 'enabled'),
            fn (?string $c) => $t->account->listAccountCompanyApiKeys(self::EMPRESA, limit: 2, starting_after: $c, status: 'active'),
            fn (?string $c) => $t->account->listAccountDianDocuments(limit: 2, starting_after: $c, company: self::EMPRESA, direction: 'issued', issued_from: '2026-11-01'),
            fn (?string $c) => $t->account->listAccountRadianEvents(limit: 2, starting_after: $c, code: '030'),
            fn (?string $c) => $t->account->listAccountRequests(limit: 2, starting_after: $c, status: 200),
        ];
        foreach ($listas as $pagina) {
            $vistos = [];
            $this->conViolaciones(function () use ($t, $pagina, &$vistos) {
                foreach ($t->paginate($pagina) as $x) {
                    $vistos[] = $x->getId();
                    if (count($vistos) === 3) { // Prism repite la misma página con has_more=true: el tope es de la prueba
                        break;
                    }
                }
            });
            $this->assertNotEmpty($vistos);
        }
    }

    public function testLlaveDeAdministracionReservaUnaEmpresaCreaYRevocaUnaLlave(): void
    {
        $t = $this->cliente(llave: self::LLAVE_ADMIN);
        $e = $this->conViolaciones(fn () => $t->account->createAccountCompany(self::ejemplo('createAccountCompany')));
        $this->assertSame('company', $e->getObject());
        $k = $this->conViolaciones(fn () => $t->account->createAccountCompanyApiKey(self::EMPRESA, self::ejemplo('createAccountCompanyApiKey')));
        $this->assertSame('api_key', $k->getObject());
        $r = $this->conViolaciones(fn () => $t->account->revokeAccountCompanyApiKey(self::EMPRESA, 'key_' . str_repeat('0', 32)));
        $this->assertSame('revoked', $r->getStatus());
    }

    public function testLlaveDeAdministracionConEmpresaEmiteYLeeEnUnaEmpresa(): void
    {
        $t = $this->cliente(llave: self::LLAVE_ADMIN)->withCompany(self::EMPRESA);
        $doc = $this->conViolaciones(fn () => $t->invoices->createInvoice(self::ejemplo('createInvoice')));
        $this->assertSame('invoice', $doc->getObject());
        $c = $this->conViolaciones(fn () => $t->general->retrieveCompany());
        $this->assertSame('company', $c->getObject());
        $directo = $this->cliente(llave: self::LLAVE_ADMIN, company: self::EMPRESA);
        $this->assertNotNull($this->conViolaciones(fn () => $directo->customers->listCustomers(limit: 1)));
    }

    public function testPreferCode409SobreElAltaEsCompanyExistsConLaEmpresa(): void
    {
        $t = $this->cliente('code=409, example=existe', llave: self::LLAVE_ADMIN);
        try {
            $t->account->createAccountCompany(self::ejemplo('createAccountCompany'));
            $this->fail('debía lanzar');
        } catch (ConflictException $e) {
            $this->assertSame('company_exists', $e->getTrevolCode());
            $this->assertStringStartsWith('com_', $e->getProblem()['company']['id']);
        }
    }

    public function testPreferCode429SobreElAltaEsCompanyLimitReachedConRetryAfter(): void
    {
        // Prism inventa la cabecera Retry-After con el esquema (`minimum: 1` → 1) sin mirar el cuerpo; la API pone en ella el mismo valor
        // de `retry_after`. Se quita para que el SDK lea el del cuerpo (1800), como si la cabecera dijera lo mismo.
        $base = Utils::chooseHandler();
        $handler = static fn (RequestInterface $r, array $o) => $base($r->withHeader('Prefer', 'code=429, example=altas'), $o)
            ->then(static fn (ResponseInterface $resp) => $resp->withoutHeader('Retry-After'));
        $esperas = [];
        $t = new Trevol(self::LLAVE_ADMIN, maxRetries: 2, baseUrl: self::$url, handler: $handler, internos: [
            'dormir' => static function (float $s) use (&$esperas): void {
                $esperas[] = $s;
            },
        ]);
        try {
            $t->account->createAccountCompany(self::ejemplo('createAccountCompany'));
            $this->fail('debía lanzar');
        } catch (RateLimitException $e) {
            $this->assertSame('company_limit_reached', $e->getTrevolCode());
            $this->assertTrue($e->isRetryable());
            $this->assertGreaterThan(60.0, $e->getRetryAfter()); // más que maxRetryAfter: el SDK no espera
            $this->assertSame([], $esperas);
        }
    }
}
