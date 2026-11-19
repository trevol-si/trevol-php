<?php

declare(strict_types=1);

namespace Trevol\Pruebas\Unitarias;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Trevol\Exception\ApiConnectionException;
use Trevol\Exception\AuthenticationException;
use Trevol\Exception\ConfigurationException;
use Trevol\Exception\ConflictException;
use Trevol\Exception\DianUnavailableException;
use Trevol\Exception\PermissionDeniedException;
use Trevol\Exception\RateLimitException;
use Trevol\Generado\Api\AccountApi;
use Trevol\Generado\Model\AccountApiKey;
use Trevol\Generado\Model\AccountCompany;
use Trevol\Generado\Model\AccountKeyInfo;
use Trevol\Generado\Tablas;
use Trevol\Opciones;
use Trevol\Pruebas\Banco;
use Trevol\Transporte;
use Trevol\Trevol;

/** La llave de administración (tva_): formato, empresa, cabeceras, el recurso `account` y que la llave nunca se vea. */
final class LlaveAdministracionTest extends TestCase
{
    private const SIN_CABECERAS = ['trevol-company', 'trevol-environment'];

    /** @return array<string, mixed> */
    private static function empresa(string $id = Banco::EMPRESA): array
    {
        return [
            'id' => $id, 'object' => 'company', 'nit' => '900373427', 'check_digit' => '6', 'name' => 'DISTRIBUIDORA LOS ANDES SAS',
            'trade_name' => null, 'status' => 'enabled', 'onboarding' => ['step' => 'done', 'stop_reason' => null],
            'emission_environment' => 'live', 'created_at' => '2026-11-01T10:00:00-05:00', 'enabled_at' => '2026-11-02T10:00:00-05:00',
            'onboarding_url' => 'https://app.trevol.si/empresas/3f0c9a1e-5b2d-4c7f-8a6e-1d2c3b4a5f60',
            'api_keys' => ['active_test' => 1, 'active_live' => 0],
        ];
    }

    /** @return array<string, mixed> */
    private static function llaveEmpresa(string $id, ?string $token = null): array
    {
        return [
            'id' => $id, 'object' => 'api_key', 'company' => Banco::EMPRESA, 'name' => 'ERP de la bodega', 'environment' => 'test',
            'permissions' => 'write', 'customers_read' => false, 'allowed_ips' => [], 'api_version' => '2026-11-18',
            'prefix' => 'tvl_test_9e8d7', 'status' => 'active', 'created_at' => '2026-11-19T09:00:00-05:00', 'last_used_at' => null,
            'revoked_at' => null, 'created_via' => 'account_key', 'token' => $token,
        ];
    }

    /** @param list<mixed> $data */
    private static function lista(array $data, bool $hasMore, ?string $siguiente = null): Response
    {
        return Banco::json(200, ['object' => 'list', 'data' => $data, 'has_more' => $hasMore, 'next_cursor' => $siguiente]);
    }

    /** @param array<string, string> $cabeceras */
    private function sinCabecerasDeEmpresa(array $cabeceras): void
    {
        $this->assertSame([], array_intersect(array_keys($cabeceras), self::SIN_CABECERAS));
    }

    // ── La llave ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testAceptaTvaTestYTvaLiveYDeduceElEntornoYElTipo(): void
    {
        $t = new Trevol(Banco::LLAVE_ADMIN_TEST);
        $this->assertSame(['test', 'account', null], [$t->environment, $t->keyType, $t->company]);
        $l = new Trevol(Banco::LLAVE_ADMIN_LIVE, environment: 'live');
        $this->assertSame(['live', 'account'], [$l->environment, $l->keyType]);
        $this->assertSame('api', (new Trevol(Banco::LLAVE_TEST))->keyType);
        $this->assertNull((new Trevol(Banco::LLAVE_LIVE))->company);
    }

    public function testLeeLaLlaveDeAdministracionDeTrevolApiKey(): void
    {
        putenv('TREVOL_API_KEY=' . Banco::LLAVE_ADMIN_LIVE);
        try {
            $t = new Trevol(company: Banco::EMPRESA);
            $this->assertSame(['live', 'account', Banco::EMPRESA], [$t->environment, $t->keyType, $t->company]);
        } finally {
            putenv('TREVOL_API_KEY');
        }
    }

    /** @return array<string, array{0: string}> */
    public static function llavesMalas(): array
    {
        $c = str_repeat('c4', 32);

        return [
            'prefijo de otro ambiente' => ["tva_prod_{$c}"], 'otro tipo' => ["tvx_test_{$c}"], 'mayúsculas' => ["TVA_TEST_{$c}"],
            'corta' => ['tva_test_' . str_repeat('c4', 31)], 'larga' => ['tva_test_' . str_repeat('c4', 33)], 'sin ambiente' => ["tva_{$c}"],
            'hex en mayúscula' => ['tva_live_' . str_repeat('C4', 32)],
        ];
    }

    #[DataProvider('llavesMalas')]
    public function testUnaTvaMalFormadaEsConfigurationExceptionSinLaLlave(string $mala): void
    {
        try {
            new Trevol($mala);
            $this->fail('debía lanzar');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('tva_test_…', $e->getMessage());
            $this->assertStringContainsString('tvl_test_…', $e->getMessage());
            $this->assertStringContainsString('https://platform.trevol.si/llaves y la de administración en https://platform.trevol.si/administracion', $e->getMessage());
            $this->assertStringNotContainsString($mala, $e->getMessage() . (string) $e);
            $this->assertStringNotContainsString(substr($mala, 9, 20), $e->getMessage());
        }
    }

    public function testElEntornoPedidoQueNoCoincideConLaTvaLanzaAntesDeLlamarYNombraElPrefijo(): void
    {
        try {
            new Trevol(Banco::LLAVE_ADMIN_TEST, environment: 'live');
            $this->fail('debía lanzar');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('tva_test_ = habilitación', $e->getMessage());
            $this->assertStringNotContainsString(str_repeat('c4', 32), (string) $e);
        }
    }

    // ── La empresa ────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testCompanyConUnaTvlEsConfigurationException(): void
    {
        try {
            new Trevol(Banco::LLAVE_TEST, company: Banco::EMPRESA);
            $this->fail('debía lanzar');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('company solo aplica a una llave de administración', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        (new Trevol(Banco::LLAVE_LIVE))->withCompany(Banco::EMPRESA);
    }

    /** @return array<string, array{0: string}> */
    public static function empresasMalas(): array
    {
        return [
            'vacía' => [''], 'mayúsculas' => ['com_3F0C9A1E5B2D4C7F8A6E1D2C3B4A5F60'], 'corta' => ['com_3f0c'],
            'otro objeto' => ['inv_3f0c9a1e5b2d4c7f8a6e1d2c3b4a5f60'], 'uuid' => ['3f0c9a1e-5b2d-4c7f-8a6e-1d2c3b4a5f60'],
            'la llave por error' => [Banco::LLAVE_ADMIN_TEST],
        ];
    }

    #[DataProvider('empresasMalas')]
    public function testUnaEmpresaMalFormadaEsConfigurationExceptionEnElConstructorYEnWithCompany(string $mala): void
    {
        foreach ([
            static fn () => new Trevol(Banco::LLAVE_ADMIN_TEST, company: $mala),
            static fn () => (new Trevol(Banco::LLAVE_ADMIN_TEST))->withCompany($mala),
        ] as $f) {
            try {
                $f();
                $this->fail('debía lanzar');
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString('com_', $e->getMessage());
                $this->assertStringNotContainsString(str_repeat('c4', 32), $e->getMessage() . (string) $e);
            }
        }
    }

    public function testLaEmpresaSeRecortaComoLaRecortaLaApi(): void
    {
        $this->assertSame(Banco::EMPRESA, (new Trevol(Banco::LLAVE_ADMIN_TEST, company: ' ' . Banco::EMPRESA . "\n"))->company);
    }

    // ── Las cabeceras ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testConTvaYEmpresaUnaRutaDeEmpresaLlevaTrevolCompanyYTrevolEnvironment(): void
    {
        $b = new Banco([Banco::json(200, Banco::DOCUMENTO)], Banco::LLAVE_ADMIN_TEST, ['company' => Banco::EMPRESA]);
        $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
        $c = $b->peticiones[0]['cabeceras'];
        $this->assertSame('Bearer ' . Banco::LLAVE_ADMIN_TEST, $c['authorization']);
        $this->assertSame(Banco::EMPRESA, $c['trevol-company']);
        $this->assertSame('test', $c['trevol-environment']);
        $this->assertSame('2026-11-18', $c['trevol-version']);
    }

    public function testConTvaLiveElAmbienteEsLive(): void
    {
        $b = new Banco([Banco::json(201, Banco::DOCUMENTO)], Banco::LLAVE_ADMIN_LIVE, ['company' => Banco::EMPRESA]);
        $b->trevol->invoices->createInvoice(Banco::ENTRADA_FACTURA);
        $c = $b->peticiones[0]['cabeceras'];
        $this->assertSame(['live', Banco::EMPRESA], [$c['trevol-environment'], $c['trevol-company']]);
        $this->assertSame('00000000-0000-4000-8000-000000000001', $c['idempotency-key']);
    }

    public function testConTvaSinEmpresaUnaRutaDeEmpresaLanzaAntesDeLaRed(): void
    {
        $b = new Banco([Banco::json(200, Banco::DOCUMENTO)], Banco::LLAVE_ADMIN_TEST);
        try {
            $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
            $this->fail('debía lanzar');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('withCompany', $e->getMessage());
            $this->assertStringContainsString("company: 'com_…'", $e->getMessage());
            $this->assertStringNotContainsString(str_repeat('c4', 32), $e->getMessage() . (string) $e);
        }
        $this->assertSame([], $b->peticiones);
        $this->assertSame([], $b->esperas);
    }

    public function testConTvaLasRutasDeCuentaYHealthNoLlevanLasCabecerasNiPidenEmpresa(): void
    {
        foreach ([null, Banco::EMPRESA] as $empresa) {
            $b = new Banco([Banco::json(200, ['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null])], Banco::LLAVE_ADMIN_TEST, ['company' => $empresa]);
            $b->trevol->account->listAccountCompanies();
            $b->trevol->account->listAccountRequests();
            $this->assertCount(2, $b->peticiones);
            foreach ($b->peticiones as $p) {
                $this->sinCabecerasDeEmpresa($p['cabeceras']);
                $this->assertSame('Bearer ' . Banco::LLAVE_ADMIN_TEST, $p['cabeceras']['authorization']);
            }
        }
        $b = new Banco([Banco::json(200, ['object' => 'health', 'status' => 'ok'])], Banco::LLAVE_ADMIN_LIVE);
        try {
            $b->trevol->general->getHealth();
        } catch (\Throwable) {
            // el modelo de /health no importa aquí: lo que se prueba es la petición
        }
        $this->assertCount(1, $b->peticiones);
        $this->sinCabecerasDeEmpresa($b->peticiones[0]['cabeceras']);
    }

    public function testUnaTvlNuncaMandaLasCabecerasEnRutasDeEmpresa(): void
    {
        $b = new Banco([Banco::json(200, Banco::DOCUMENTO)], Banco::LLAVE_TEST);
        $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
        $this->assertCount(1, $b->peticiones);
        $this->sinCabecerasDeEmpresa($b->peticiones[0]['cabeceras']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function operaciones(): array
    {
        $salida = [];
        foreach (Banco::datos('operaciones.json') as $o) {
            $salida[$o['operationId']] = [$o];
        }

        return $salida;
    }

    /**
     * Cada operación de los casos compartidos, por el middleware: con una tva_ y empresa, las cabeceras van solo donde `llave` es
     * `api_o_administracion`; sin empresa, esas lanzan antes de la red y las demás pasan; con una tvl_, nunca (aunque la petición ya las
     * trajera).
     *
     * @param array<string, mixed> $o
     */
    #[DataProvider('operaciones')]
    public function testLaColumnaLlaveDecideLasCabecerasEnCadaOperacion(array $o): void
    {
        $this->assertContains($o['llave'], ['api_o_administracion', 'administracion', 'ninguna']);
        $ruta = (string) preg_replace('/\{[a-z_]+\}/', 'x_' . Banco::HEX, $o['ruta']);
        $this->assertSame($o['llave'], Transporte::llave(Transporte::operacion($o['metodo'], $ruta), $ruta));
        $empresaRuta = $o['llave'] === 'api_o_administracion';

        $vistas = [];
        $pasar = static function (string $llave, ?string $empresa) use ($o, $ruta, &$vistas): void {
            $tipo = str_starts_with($llave, 'tva_') ? 'account' : 'api';
            $opciones = new Opciones(
                $llave, '2026-11-18', 'prueba', 0, 60.0, '/v1',
                static fn (float $s) => null, static fn (): float => 1.0, static fn (): string => '00000000-0000-4000-8000-000000000001',
                $tipo, str_contains($llave, '_live_') ? 'live' : 'test', $empresa,
            );
            $red = static function (RequestInterface $r, array $op) use (&$vistas) {
                $vistas[] = $r;

                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{}'));
            };
            $pedida = new Request($o['metodo'], 'http://127.0.0.1:9/v1' . $ruta, [
                'Trevol-Company' => Banco::OTRA_EMPRESA, 'Trevol-Environment' => 'live', // lo que el SDK nunca deja pasar tal cual
            ]);
            Transporte::middleware($opciones)($red)($pedida, [])->wait();
        };

        $pasar(Banco::LLAVE_ADMIN_TEST, Banco::EMPRESA);
        $r = array_pop($vistas);
        if ($empresaRuta) {
            $this->assertSame([Banco::EMPRESA], $r->getHeader('Trevol-Company'));
            $this->assertSame(['test'], $r->getHeader('Trevol-Environment'));
        } else {
            $this->assertFalse($r->hasHeader('Trevol-Company'));
            $this->assertFalse($r->hasHeader('Trevol-Environment'));
        }

        try {
            $pasar(Banco::LLAVE_ADMIN_LIVE, null);
            $this->assertFalse($empresaRuta, 'una ruta de empresa sin empresa debía lanzar');
            $r = array_pop($vistas);
            $this->assertFalse($r->hasHeader('Trevol-Company'));
        } catch (ConfigurationException) {
            $this->assertTrue($empresaRuta);
            $this->assertSame([], $vistas);
        }

        foreach ([Banco::LLAVE_TEST, Banco::LLAVE_LIVE] as $tvl) {
            if ($o['llave'] === 'administracion') {
                try {
                    $pasar($tvl, null);
                    $this->fail("{$o['operationId']} es de administración: con una tvl_ debía lanzar antes de la red");
                } catch (ConfigurationException $e) {
                    $this->assertSame(Transporte::SOLO_ADMINISTRACION, $e->getMessage());
                    $this->assertStringNotContainsString($tvl, $e->getMessage());
                    $this->assertSame([], $vistas);
                }
                continue;
            }
            $pasar($tvl, null);
            $r = array_pop($vistas);
            $this->assertFalse($r->hasHeader('Trevol-Company'));
            $this->assertFalse($r->hasHeader('Trevol-Environment'));
            $this->assertSame(['Bearer ' . $tvl], $r->getHeader('Authorization'));
        }
    }

    public function testLasTablasMarcanLasDiezDeCuentaComoAdministracionYHealthSinLlave(): void
    {
        $porLlave = [];
        foreach (Tablas::OPERACIONES as $o) {
            $porLlave[$o['llave']][] = $o['operationId'];
        }
        $this->assertCount(10, $porLlave['administracion']);
        $this->assertSame(['getHealth'], $porLlave['ninguna']);
        foreach (Tablas::OPERACIONES as $o) {
            $this->assertSame($o['recurso'] === 'Account', $o['llave'] === 'administracion', $o['operationId']);
        }
    }

    public function testUnaRutaQueLasTablasNoConocenSeClasificaPorSuForma(): void
    {
        $this->assertSame('administracion', Transporte::llave(null, '/account'));
        $this->assertSame('administracion', Transporte::llave(null, '/account/algo/nuevo'));
        $this->assertSame('ninguna', Transporte::llave(null, '/health'));
        $this->assertSame('api_o_administracion', Transporte::llave(null, '/accounting'));
        $this->assertSame('api_o_administracion', Transporte::llave(null, '/algo_nuevo'));
    }

    // ── withCompany ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testWithCompanyDaOtroClienteQueCompartElTransporteYNoCambiaElOriginal(): void
    {
        $b = new Banco([Banco::json(200, Banco::DOCUMENTO)], Banco::LLAVE_ADMIN_LIVE, ['apiVersion' => '2026-10-22', 'company' => Banco::EMPRESA]);
        $otra = $b->trevol->withCompany(Banco::OTRA_EMPRESA);
        $this->assertNotSame($b->trevol, $otra);
        $this->assertSame([Banco::EMPRESA, Banco::OTRA_EMPRESA], [$b->trevol->company, $otra->company]);
        $this->assertSame(['live', 'account', '2026-10-22'], [$otra->environment, $otra->keyType, $otra->apiVersion]);
        $this->assertInstanceOf(AccountApi::class, $otra->account);

        $otra->invoices->retrieveInvoice(Banco::ID_DOC);
        $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
        $otra->withCompany(Banco::EMPRESA)->customers->listCustomers();
        // El mismo manejador de red (el del banco) vio las tres: el derivado comparte transporte.
        $this->assertSame(
            [Banco::OTRA_EMPRESA, Banco::EMPRESA, Banco::EMPRESA],
            array_map(static fn ($p) => $p['cabeceras']['trevol-company'], $b->peticiones),
        );
        foreach ($b->peticiones as $p) {
            $this->assertSame('live', $p['cabeceras']['trevol-environment']);
            $this->assertSame('2026-10-22', $p['cabeceras']['trevol-version']);
            $this->assertSame('Bearer ' . Banco::LLAVE_ADMIN_LIVE, $p['cabeceras']['authorization']);
        }
    }

    public function testWithCompanyDesdeUnClienteSinEmpresaYConservaReintentosYClaves(): void
    {
        $b = new Banco([
            Banco::problema(503, 'dian_unavailable', ['retryable' => true]), Banco::json(201, Banco::DOCUMENTO),
        ], Banco::LLAVE_ADMIN_TEST);
        $andes = $b->trevol->withCompany(Banco::EMPRESA);
        $andes->invoices->createInvoice(Banco::ENTRADA_FACTURA);
        $this->assertCount(2, $b->peticiones);
        $this->assertSame(['00000000-0000-4000-8000-000000000001'], array_values(array_unique(
            array_map(static fn ($p) => $p['cabeceras']['idempotency-key'], $b->peticiones)
        )));
        $this->assertCount(1, $b->esperas);
        $this->assertNull($b->trevol->company);
    }

    // ── El recurso account ────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testRetrieveAccountDevuelveElModelo(): void
    {
        $b = new Banco([Banco::json(200, [
            'object' => 'account_key_info', 'account' => ['id' => 'acct_' . Banco::HEX],
            'key' => [
                'id' => 'akey_' . Banco::HEX, 'object' => 'account_key', 'prefix' => 'tva_test_c4c4c', 'environment' => 'test',
                'permissions' => ['companies:read', 'documents:read'], 'allowed_ips' => [], 'api_version' => '2026-11-18',
                'scope' => ['type' => 'all', 'companies' => []],
            ],
        ])], Banco::LLAVE_ADMIN_TEST);
        $info = $b->trevol->account->retrieveAccount();
        $this->assertInstanceOf(AccountKeyInfo::class, $info);
        $this->assertSame('acct_' . Banco::HEX, $info->getAccount()->getId());
        $this->assertSame(['companies:read', 'documents:read'], $info->getKey()->getPermissions());
        $this->assertSame('all', $info->getKey()->getScope()->getType());
        $this->assertSame(['GET', '/v1/account'], [$b->peticiones[0]['metodo'], $b->peticiones[0]['ruta']]);
        $this->assertSame('application/json, application/problem+json', $b->peticiones[0]['cabeceras']['accept']);
    }

    public function testPaginaLasEmpresasConStartingAfter(): void
    {
        $b = new Banco([
            self::lista([self::empresa(), self::empresa(Banco::OTRA_EMPRESA)], true, Banco::OTRA_EMPRESA),
            self::lista([self::empresa('com_' . Banco::HEX)], false),
        ], Banco::LLAVE_ADMIN_TEST);
        $t = $b->trevol;
        $ids = [];
        foreach ($t->paginate(fn (?string $c) => $t->account->listAccountCompanies(limit: 2, starting_after: $c, status: 'enabled')) as $e) {
            $this->assertInstanceOf(AccountCompany::class, $e);
            $ids[] = $e->getId();
        }
        $this->assertSame([Banco::EMPRESA, Banco::OTRA_EMPRESA, 'com_' . Banco::HEX], $ids);
        $this->assertSame('/v1/account/companies?limit=2&status=enabled', $b->peticiones[0]['ruta']);
        $this->assertSame('/v1/account/companies?limit=2&starting_after=' . Banco::OTRA_EMPRESA . '&status=enabled', $b->peticiones[1]['ruta']);
        $this->assertSame('6', $t->account->listAccountCompanies()->getData()[0]->getCheckDigit());
    }

    public function testPaginaLasListasDeLlavesDocumentosEventosYPeticionesSinNextCursor(): void
    {
        $casos = [
            ['key_', fn (Trevol $t, ?string $c) => $t->account->listAccountCompanyApiKeys(Banco::EMPRESA, starting_after: $c), '/v1/account/companies/' . Banco::EMPRESA . '/api_keys'],
            ['ddoc_', fn (Trevol $t, ?string $c) => $t->account->listAccountDianDocuments(starting_after: $c, direction: 'received'), '/v1/account/dian_documents'],
            ['rev_', fn (Trevol $t, ?string $c) => $t->account->listAccountRadianEvents(starting_after: $c, company: Banco::EMPRESA), '/v1/account/radian_events'],
            ['req_', fn (Trevol $t, ?string $c) => $t->account->listAccountRequests(starting_after: $c), '/v1/account/requests'],
        ];
        foreach ($casos as [$prefijo, $pagina, $ruta]) {
            $hex = $prefijo === 'req_' ? substr(Banco::HEX, 0, 24) : Banco::HEX;
            $b = new Banco([
                self::lista([['id' => "{$prefijo}1{$hex}"], ['id' => "{$prefijo}2{$hex}"]], true), // sin next_cursor: el id de la última
                self::lista([['id' => "{$prefijo}3{$hex}"]], false),
            ], Banco::LLAVE_ADMIN_LIVE);
            $t = $b->trevol;
            $ids = [];
            foreach ($t->paginate(fn (?string $c) => $pagina($t, $c)) as $x) {
                $ids[] = $x->getId();
            }
            $this->assertSame(["{$prefijo}1{$hex}", "{$prefijo}2{$hex}", "{$prefijo}3{$hex}"], $ids, $ruta);
            $this->assertStringStartsWith($ruta, $b->peticiones[0]['ruta']);
            $this->assertStringContainsString("starting_after={$prefijo}2{$hex}", $b->peticiones[1]['ruta']);
            foreach ($b->peticiones as $p) {
                $this->sinCabecerasDeEmpresa($p['cabeceras']);
            }
        }
    }

    public function testCrearUnaEmpresaLlevaIdempotencyKeyAutomaticaYElCuerpo(): void
    {
        $b = new Banco([
            Banco::problema(503, 'internal_error', ['retryable' => true]),
            Banco::json(201, ['status' => 'pending', 'enabled_at' => null, 'emission_environment' => null] + self::empresa()),
        ], Banco::LLAVE_ADMIN_LIVE);
        $e = $b->trevol->account->createAccountCompany(['nit' => '901234567', 'name' => 'FERRETERÍA EL ROBLE SAS', 'email' => 'facturacion@elroble.example']);
        $this->assertSame('pending', $e->getStatus());
        $this->assertCount(2, $b->peticiones);
        foreach ($b->peticiones as $p) {
            $this->assertSame(['POST', '/v1/account/companies'], [$p['metodo'], $p['ruta']]);
            $this->assertSame('00000000-0000-4000-8000-000000000001', $p['cabeceras']['idempotency-key']);
            $this->assertSame(['nit' => '901234567', 'name' => 'FERRETERÍA EL ROBLE SAS', 'email' => 'facturacion@elroble.example'], json_decode($p['cuerpo'], true));
            $this->sinCabecerasDeEmpresa($p['cabeceras']);
        }
    }

    public function testCrearLaLlaveDeUnaEmpresaDevuelveElTokenUnaVezYRespetaLaClaveDada(): void
    {
        $token = 'tvl_test_' . str_repeat('9e', 32);
        $b = new Banco([Banco::json(201, self::llaveEmpresa('key_' . Banco::HEX, $token))], Banco::LLAVE_ADMIN_TEST);
        $k = $b->trevol->account->createAccountCompanyApiKey(Banco::EMPRESA, ['name' => 'ERP de la bodega', 'permissions' => 'write'], 'ALTA-LLAVE-1');
        $this->assertInstanceOf(AccountApiKey::class, $k);
        $this->assertSame($token, $k->getToken());
        $this->assertSame(Banco::EMPRESA, $k->getCompany());
        $this->assertSame('ALTA-LLAVE-1', $b->peticiones[0]['cabeceras']['idempotency-key']);
        $this->assertSame('/v1/account/companies/' . Banco::EMPRESA . '/api_keys', $b->peticiones[0]['ruta']);
    }

    /** Al repetirla con la misma Idempotency-Key la API devuelve la llave con token null: un reintento a ciegas perdería el token. */
    public function testCrearLaLlaveDeUnaEmpresaNoSeReintentaAnteUn5xxNiUnCorteYSiAnteUn429(): void
    {
        $op = array_values(array_filter(Tablas::OPERACIONES, static fn (array $o): bool => $o['operationId'] === 'createAccountCompanyApiKey'))[0];
        $this->assertTrue($op['idempotente']);
        $this->assertSame('solo_429', $op['reintento']);
        $entrada = ['name' => 'ERP de la bodega', 'permissions' => 'write'];

        $b = new Banco([Banco::problema(503, 'dian_unavailable', ['retryable' => true])], Banco::LLAVE_ADMIN_TEST);
        try {
            $b->trevol->account->createAccountCompanyApiKey(Banco::EMPRESA, $entrada);
            $this->fail('debía lanzar');
        } catch (DianUnavailableException $e) {
            $this->assertSame('00000000-0000-4000-8000-000000000001', $e->getIdempotencyKey());
        }
        $this->assertCount(1, $b->peticiones);
        $this->assertSame([], $b->esperas);

        $b = new Banco([new ConnectException('boom', new Request('POST', 'http://x/'))], Banco::LLAVE_ADMIN_TEST);
        try {
            $b->trevol->account->createAccountCompanyApiKey(Banco::EMPRESA, $entrada);
            $this->fail('debía lanzar');
        } catch (ApiConnectionException) {
        }
        $this->assertCount(1, $b->peticiones);

        $token = 'tvl_test_' . str_repeat('9e', 32);
        $b = new Banco([
            Banco::problema(429, 'rate_limited', ['retryable' => true, 'limit' => 'api_keys'], ['Retry-After' => '2']),
            Banco::json(201, self::llaveEmpresa('key_' . Banco::HEX, $token)),
        ], Banco::LLAVE_ADMIN_TEST);
        $k = $b->trevol->account->createAccountCompanyApiKey(Banco::EMPRESA, $entrada);
        $this->assertSame($token, $k->getToken());
        $this->assertCount(2, $b->peticiones);
        $this->assertSame([2.0], $b->esperas);
        $this->assertSame($b->peticiones[0]['cabeceras']['idempotency-key'], $b->peticiones[1]['cabeceras']['idempotency-key']);
    }

    public function testRevocarEsDeleteSinIdempotencyKeyYSoloSeReintentaCon429(): void
    {
        $b = new Banco([Banco::problema(500, 'internal_error', ['retryable' => true])], Banco::LLAVE_ADMIN_TEST);
        try {
            $b->trevol->account->revokeAccountCompanyApiKey(Banco::EMPRESA, 'key_' . Banco::HEX);
            $this->fail('debía lanzar');
        } catch (\Trevol\Exception\InternalServerException) {
        }
        $this->assertCount(1, $b->peticiones);
        $this->assertArrayNotHasKey('idempotency-key', $b->peticiones[0]['cabeceras']);

        $b = new Banco([
            Banco::problema(429, 'rate_limited', ['limit' => 'api_keys'], ['Retry-After' => '1']),
            Banco::json(200, ['id' => 'key_' . Banco::HEX, 'object' => 'api_key', 'status' => 'revoked']),
        ], Banco::LLAVE_ADMIN_TEST);
        $r = $b->trevol->account->revokeAccountCompanyApiKey(Banco::EMPRESA, 'key_' . Banco::HEX);
        $this->assertSame('revoked', $r->getStatus());
        $this->assertSame(['DELETE', 'DELETE'], array_column($b->peticiones, 'metodo'));
    }

    // ── Errores de la llave de administración ─────────────────────────────────────────────────────────────────────────────────────

    public function testCompanyExistsEsConflictExceptionConLaEmpresa(): void
    {
        $b = new Banco([Banco::problema(409, 'company_exists', ['company' => self::empresa()])], Banco::LLAVE_ADMIN_LIVE);
        try {
            $b->trevol->account->createAccountCompany(['nit' => '900373427']);
            $this->fail('debía lanzar');
        } catch (ConflictException $e) {
            $this->assertSame('company_exists', $e->getTrevolCode());
            $this->assertSame(Banco::EMPRESA, $e->getProblem()['company']['id']);
            $this->assertSame('00000000-0000-4000-8000-000000000001', $e->getIdempotencyKey());
        }
        $this->assertCount(1, $b->peticiones);
    }

    public function testCompanyLimitReachedConUnaHoraDeEsperaNoEsperaYLanzaConRetryAfter(): void
    {
        $b = new Banco([Banco::problema(429, 'company_limit_reached', ['retryable' => true, 'retry_after' => 3600, 'limit' => 'hour'], ['Retry-After' => '3600'])], Banco::LLAVE_ADMIN_LIVE);
        try {
            $b->trevol->account->createAccountCompany(['nit' => '901234567']);
            $this->fail('debía lanzar');
        } catch (RateLimitException $e) {
            $this->assertSame(3600.0, $e->getRetryAfter());
            $this->assertSame('hour', $e->getProblem()['limit']);
        }
        $this->assertCount(1, $b->peticiones);
        $this->assertSame([], $b->esperas);
    }

    public function testKeyLimitReachedEsConflictYEnvironmentMismatchEsPermisoDenegado(): void
    {
        $b = new Banco([Banco::problema(409, 'key_limit_reached')], Banco::LLAVE_ADMIN_TEST);
        try {
            $b->trevol->account->createAccountCompanyApiKey(Banco::EMPRESA, ['name' => 'Otra', 'permissions' => 'read']);
            $this->fail('debía lanzar');
        } catch (ConflictException $e) {
            $this->assertSame('key_limit_reached', $e->getTrevolCode());
        }
        $b = new Banco([Banco::problema(403, 'permission_denied', ['reason' => 'environment_mismatch'])], Banco::LLAVE_ADMIN_TEST, ['company' => Banco::EMPRESA]);
        try {
            $b->trevol->invoices->listInvoices();
            $this->fail('debía lanzar');
        } catch (PermissionDeniedException $e) {
            $this->assertSame('environment_mismatch', $e->getProblem()['reason']);
        }
    }

    /** Con una tvl_, las diez de `$trevol->account` lanzan ConfigurationException antes de la red (la API contestaría 401). */
    public function testUnaTvlEnUnaRutaDeCuentaLanzaAntesDeLaRedSinLaLlave(): void
    {
        $llamadas = [
            fn (Trevol $t) => $t->account->retrieveAccount(),
            fn (Trevol $t) => $t->account->listAccountCompanies(),
            fn (Trevol $t) => $t->account->createAccountCompany(['nit' => '901234567']),
            fn (Trevol $t) => $t->account->retrieveAccountCompany(Banco::EMPRESA),
            fn (Trevol $t) => $t->account->listAccountCompanyApiKeys(Banco::EMPRESA),
            fn (Trevol $t) => $t->account->createAccountCompanyApiKey(Banco::EMPRESA, ['name' => 'ERP', 'permissions' => 'read']),
            fn (Trevol $t) => $t->account->revokeAccountCompanyApiKey(Banco::EMPRESA, 'key_' . Banco::HEX),
            fn (Trevol $t) => $t->account->listAccountDianDocuments(),
            fn (Trevol $t) => $t->account->listAccountRadianEvents(),
            fn (Trevol $t) => $t->account->listAccountRequests(),
        ];
        foreach ([Banco::LLAVE_TEST, Banco::LLAVE_LIVE] as $tvl) {
            foreach ($llamadas as $i => $llamar) {
                $b = new Banco([Banco::problema(401, 'invalid_api_key')], $tvl);
                try {
                    $llamar($b->trevol);
                    $this->fail("la llamada {$i} debía lanzar ConfigurationException");
                } catch (ConfigurationException $e) {
                    $this->assertStringContainsString('tva_test_…', $e->getMessage());
                    $this->assertStringNotContainsString($tvl, $e->getMessage());
                    $this->assertSame([], $b->peticiones, "la llamada {$i} salió a la red");
                }
            }
        }
    }

    public function testUnaTvaEnUnaRutaDeCuentaRecibeElErrorDeLaApi(): void
    {
        $b = new Banco([Banco::problema(401, 'invalid_api_key')], Banco::LLAVE_ADMIN_TEST);
        $this->expectException(AuthenticationException::class);
        $b->trevol->account->retrieveAccount();
    }

    // ── La llave nunca se ve ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testNiVarDumpNiPrintRNiVarExportNiJsonNiElCastMuestranLaLlaveTampocoEnElDerivado(): void
    {
        $t = new Trevol(Banco::LLAVE_ADMIN_TEST, company: Banco::EMPRESA);
        foreach ([$t, $t->withCompany(Banco::OTRA_EMPRESA)] as $c) {
            ob_start();
            var_dump($c);
            print_r($c);
            $salida = (string) ob_get_clean();
            $salida .= var_export($c, true) . json_encode($c) . print_r((array) $c, true);
            $this->assertStringNotContainsString(str_repeat('c4', 32), $salida);
            $this->assertStringContainsString('account', $salida);
        }
        $this->assertSame(
            ['environment' => 'test', 'apiVersion' => '2026-11-18', 'keyType' => 'account', 'company' => Banco::EMPRESA],
            $t->__debugInfo(),
        );
    }

    public function testElClienteNoSeSerializa(): void
    {
        $this->expectException(\Exception::class);
        serialize(new Trevol(Banco::LLAVE_ADMIN_TEST));
    }
}
